<?php

declare(strict_types=1);

namespace Max\AntiGlitch;

use pocketmine\math\Vector3;
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
			"PearlGlitching" => false,
			"PlaceBlockGlitching" => true,
			"BreakBlockGlitching" => true,
            "OpenDoorGlitching" => true,
			"CommandGlitching" => true,
			"CancelPearl-Message" => false,
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
        if (time() != $this->pearlland[$entity->getName()]) return; //Check if teleportation was caused by enderpearl (by checking is a projectile landed at the same time as teleportation) TODO Find a less hacky way of doing this?

        //Get coords and adjust for negative quadrants.
        $x = $to->getX();
        $y = $to->getY();
        $z = $to->getZ();
        if($x < 0) $x = $x - 1;
        if($z < 0) $z = $z - 1;

        //If pearl is in a block as soon as it lands (which could only mean it was shot into a block over a fence), put it back down in the fence. TODO Find a less hacky way of doing this?
        if($this->isInHitbox($level, $x, $y, $z)) $y = $y - 0.5;

        //Try to find a good place to teleport.
        foreach (range(0, 1.9, 0.05) as $n) {
			$xb = $x;
			$yb = ($y - $n);
			$zb = $z;

			if ($this->isInHitbox($level, ($x + 0.05), $yb, $z)) $xb = $xb - 0.3;
			if ($this->isInHitbox($level, ($x - 0.05), $yb, $z)) $xb = $xb + 0.3;
			if ($this->isInHitbox($level, $x, $yb, ($z - 0.05))) $zb = $zb + 0.3;
			if ($this->isInHitbox($level, $x, $yb, ($z + 0.05))) $zb = $zb - 0.3;


            if($this->isInHitbox($level, $xb, $yb, $zb)) {
                break;
            } else {
				$x = $xb;
				$y = $yb;
				$z = $zb;
			}
        }

		//Check if pearl lands in an area too small for the player
		foreach (range(0.1, 1.8, 0.1) as $n) {
			if($this->isInHitbox($level, $x, ($y + $n), $z)) {

				//Teleport the player into the middle of the block so they can't phase into an adjacent block.
				$blockHitBox = $level->getBlockAt((int)$xb, (int)$yb, (int)$zb)->getCollisionBoxes()[0];
				if($x < 0) {
					$x = (($blockHitBox->minX + $blockHitBox->maxX) / 2) - 1;
				} else {
					$x = ($blockHitBox->minX + $blockHitBox->maxX) / 2;
				}
				if($z < 0) {
					$z = (($blockHitBox->minZ + $blockHitBox->maxZ) / 2) - 1;
				} else {
					$z = ($blockHitBox->minZ + $blockHitBox->maxZ) / 2;
				}

				//Prevent pearling into areas too small (configurable in config)
				if ($this->config->get("PearlGlitching")) {
					if ($this->config->get("CancelPearl-Message")) {
						$entity->sendMessage($this->config->get("CancelPearl-Message"));
					}
					$event->setCancelled();
				}

				break;
			}
		}

        //Readjust for negative quadrants
        if($x < 0) $x = $x + 1;
        if($z < 0) $z = $z + 1;

        //Send new safe location
        $event->setTo(new Location($x, $y, $z));
    }

    //Check if a set of coords are inside a block's HitBox
    public function isInHitbox($level, $x, $y, $z) {
        if(!isset($level->getBlockAt((int)$x, (int)$y, (int)$z)->getCollisionBoxes()[0])) return False;
        foreach ($level->getBlockAt((int)$x, (int)$y, (int)$z)->getCollisionBoxes() as $blockHitBox) {
			if($x < 0) $x = $x + 1;
			if($z < 0) $z = $z + 1;
			if (($blockHitBox->minX < $x) AND ($x < $blockHitBox->maxX) AND ($blockHitBox->minY < $y) AND ($y < $blockHitBox->maxY) AND ($blockHitBox->minZ < $z) AND ($z < $blockHitBox->maxZ)) return True;
		}
        return False;
    }

	public function onInteract(PlayerInteractEvent $event){
		if ($this->config->get("OpenDoorGlitching")) {
			if ($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) return;
			$player = $event->getPlayer();
			if ($player->getGamemode() !== 0) return;
			$block = $event->getBlock();
			if ($event->isCancelled()) {
				if (in_array($block->getId(), [107, 183, 184, 185, 186, 187, 324, 427, 428, 429, 430, 431, 96, 167, 330])) {

					$player->teleport($player);
					$dir = $player->getDirection();
					$x = 0;
					$y = 0;
					$z = 0;
					if (($block->getY() <= $player->getY() - 1) AND ($block->getY() >= $player->getY() - 1.3)) $y = 0.3; #If block is under the player
					elseif (($block->getY() >= (int)$player->getY()) AND ($block->getY() <= (int)$player->getY() + 2)) { #If block is on the side of the player
						if ($dir == 0) $x = -0.2;
						elseif ($dir == 1) $z = -0.2;
						elseif ($dir == 2) $x = 0.2;
						elseif ($dir == 3) $z = 0.2;
					}
					$player->setMotion(new Vector3($x, $y, $z));

					if ($this->config->get("CancelOpenDoor-Message")) {
						$player->sendMessage($this->config->get("CancelOpenDoor-Message"));
					}
				}
			}
		}
	}

    /**
     * @priority HIGHEST
     * @ignoreCancelled False
     */

    public function onBlockBreak(BlockBreakEvent $event) {
        if ($this->config->get("BreakBlockGlitching")) {
            $player = $event->getPlayer();
            $block = $event->getBlock();
            if ($player->getGamemode() !== 0) return;
            if ($event->isCancelled()) {
				$player->teleport($player);
				$dir = $player->getDirection();
				$x = 0;
				$y = 0;
				$z = 0;
				if (($block->getY() <= $player->getY() - 1) AND ($block->getY() >= $player->getY() - 1.3)) $y = 0.3; #If block is under the player
				elseif (($block->getY() >= (int)$player->getY()) AND ($block->getY() <= (int)$player->getY() + 2)) { #If block is on the side of the player
					if ($dir == 0) $x = -0.2;
					elseif ($dir == 1) $z = -0.2;
					elseif ($dir == 2) $x = 0.2;
					elseif ($dir == 3) $z = 0.2;
				}
				$player->setMotion(new Vector3($x, $y, $z));
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

    public function onBlockPlace(BlockPlaceEvent $event) {
        if ($this->config->get("PlaceBlockGlitching")) {
            $player = $event->getPlayer();
			$block = $event->getBlock();
            if ($player->getGamemode() !== 0) return;
			$event->setCancelled();
            if ($event->isCancelled()) {
				$player->teleport($player, $player->getYaw(), 1);
                if ($this->config->get("CancelBlockPlace-Message")) {
                    $player->sendMessage($this->config->get("CancelBlockPlace-Message"));
                }
            }
        }
    }

	public function onCommandPre(PlayerCommandPreprocessEvent $event){
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
