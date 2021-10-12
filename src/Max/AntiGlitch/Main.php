<?php

declare(strict_types=1);

namespace Max\AntiGlitch;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;

use pocketmine\Player;
use pocketmine\utils\Config;
use pocketmine\entity\projectile\EnderPearl;
use pocketmine\level\{Position, Location};

use pocketmine\event\player\{PlayerCommandPreprocessEvent, PlayerInteractEvent};
use pocketmine\event\entity\{ProjectileHitEvent, EntityTeleportEvent};
use pocketmine\event\block\{BlockBreakEvent, BlockPlaceEvent};

class Main extends PluginBase implements Listener {

    private $pearlland;

    public function onEnable() {
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder()."config.yml", Config::YAML);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->DefaultConfig = array(
			"PearlGlitching" => true,
			"PlaceBlockGlitching" => true,
			"BreakBlockGlitching" => true,
            "OpenDoorGlitching" => true,
			"CommandGlitching" => true,
			"CancelPearl-Message" => "§7[§bAntiGlitch§7] §cPearl Cancelled due to suffocation!",
			"CancelBlockPlace-Message" => false,
			"CancelBlockBreak-Message" => false,
            "CancelOpenDoor-Message" => false,
			"CancelCommand-Message" => "§7[§bAntiGlitch§7] §cCommand Cancelled due to invalid format!"
		);

		//Automatically update config file if plugin gets updated
		if ($this->config->getAll() != $this->DefaultConfig) {
			foreach ($this->DefaultConfig as $key => $data) {
				if($this->config->exists($key)) {
					$this->DefaultConfig[$key] = $this->config->get($key);
				}
			}
			$this->config->setAll($this->DefaultConfig);
			$this->config->save();
		}
    }


        public function onPearlLandBlock(ProjectileHitEvent $event) {
        $player = $event->getEntity()->getOwningEntity();
        if ($player instanceof Player && $event->getEntity() instanceof EnderPearl) $this->pearlland[$player->getName()] = time();
    }

    public function onTP(EntityTeleportEvent $event) {
        $entity = $event->getEntity();
        if (!$entity instanceof Player) return;
        $level = $entity->getLevel();
        $to = $event->getTo();
        if (!isset($this->pearlland[$entity->getName()])) return;
        if (time() = $this->pearlland[$entity->getName()]) return; //Check if teleportation was caused by enderpearl (by checking is a projectile landed at the same time as teleportation)

        //Get coords and adjust for negative quadrents.
        $x = $to->getX();
        $y = $to->getY();
        $z = $to->getZ();
        if($x < 0) $x = $x - 1;
        if($z < 0) $z = $z - 1;

        //If pearl is in a block as soon as it lands (meaning it was shot into a block over a fence), put it back down in the fence.
        if($this->isInHitbox($level, $x, $y, $z)) $y = $y - 0.5;

        //Adjust sides
        if($this->isInHitbox($level, ($x + 0.01), $y, $z)) $x = $x - 0.3;
        if($this->isInHitbox($level, ($x - 0.01), $y, $z)) $x = $x + 0.3;
        if($this->isInHitbox($level, $x, $y, ($z - 0.01))) $z = $z + 0.3;
        if($this->isInHitbox($level, $x, $y, ($z + 0.01))) $z = $z - 0.3;

        //Now that the point of landing is safe, make sure the whole body would be sage upon landing:
        $yb = $y;
        foreach (range(0, 1.9, 0.1) as $n) {
            if($this->isInHitbox($level, $x, $yb - $n, $z)) {
                break;
            } else {
                $y = $yb - $n;
            }
        }

        //Prevent pearling in 1 block high areas (configurable in config)
        if ($this->config->get("PearlGlitching")) {
            foreach (range(0.1, 1.8, 0.1) as $n) {
                if($this->isInHitbox($level, $x, ($y + $n), $z)) {
                    if ($this->config->get("CancelPearl-Message")) {
                        $entity->sendMessage($this->config->get("CancelPearl-Message"));
                    }
                    $event->setCancelled();
                    break;
                }
            }
        }

        //Reajust for negative quadrents
        if($x < 0) $x = $x + 1;
        if($z < 0) $z = $z + 1;

        //Send new safe location
        $event->setTo(new Location($x, $y, $z));
    }

    //Check if a set of coords are inside a blocks hitbox
    public function isInHitbox($level, $xx, $yy, $zz) {
        if(!isset($level->getBlockAt((int)$xx, (int)$yy, (int)$zz)->getCollisionBoxes()[0])) return False;
        $block = $level->getBlockAt((int)$xx, (int)$yy, (int)$zz)->getCollisionBoxes()[0];

        if($xx < 0) $xx = $xx + 1;
        if($zz < 0) $zz = $zz + 1;

        if (($block->minX < $xx) AND ($xx < $block->maxX) AND ($block->minY < $yy) AND ($yy < $block->maxY) AND ($block->minZ < $zz) AND ($zz < $block->maxZ)) {
            return True;
        } else {
            return False;
        }
    }

	public function onInteract(PlayerInteractEvent $event){
		if ($this->config->get("OpenDoorGlitching")) {
			if ($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) return;
			$player = $event->getPlayer();
			if ($player->getGamemode() !== 0) return;
			$block = $event->getBlock();
			if ($event->isCancelled()) {
				if (in_array($block->getId(), [107, 183, 184, 185, 186, 187, 324, 427, 428, 429, 430, 431, 96, 167, 330])) {
					$player->teleport($player, 1, 1);
					if ($this->config->get("CancelOpenDoor-Message")) {
						$player->sendMessage($this->config->get("CancelOpenDoor-Message"));
					}
				}
			}
		}
	}

    //Fix for glitch: Placing or mining blocks very fast in areas not allowed to in order to get somewhere normally not accessible.

    /**
     * @priority HIGHEST
     * @ignoreCancelled False
     */

    public function fixBlockBreakGlitch(BlockBreakEvent $event) {
        if ($this->config->get("BreakBlockGlitching")) {
            $player = $event->getPlayer();
            if ($player->getGamemode() !== 0) return;
            if ($event->isCancelled()) {
				$player->teleport($player, 1, 1);
				if ($this->config->get("CancelBlockBreak-Message")) {
					$player->sendMessage($this->config->get("CancelBlockBreak-Message"));
				}
            }
        }
    }

    /**
     * @priority HIGHEST
     * @ignoreCancelled False
     */

    public function fixBlockPlaceGlitch(BlockPlaceEvent $event) {
        if ($this->config->get("PlaceBlockGlitching")) {
            $player = $event->getPlayer();
            if ($player->getGamemode() !== 0) return;
            if ($event->isCancelled()) {
				$player->teleport($player, 1, 1);
                if ($this->config->get("CancelBlockPlace-Message")) {
                    $player->sendMessage($this->config->get("CancelBlockPlace-Message"));
                }
            }
        }
    }


    //Fix for glitch: People putting a space before the slash in their commands to evade combat timer.

	public function fixCommandSpace(PlayerCommandPreprocessEvent $event){
        if ($this->config->get("CommandGlitching")) {
            if((substr($event->getMessage(), 0, 2) == "/ ") || (substr($event->getMessage(), 0, 2) == "/\\") || (substr($event->getMessage(), 0, 2) == "/\"") || (substr($event->getMessage(), -1, 1) === "\\")){
                $event->setCancelled();
                if ($this->config->get("CancelCommand-Message")) {
                    $event->getPlayer()->sendMessage($this->config->get("CancelCommand-Message"));
                }
            }
        }
    }
}
