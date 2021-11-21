<?php

declare(strict_types=1);

namespace Max\AntiGlitch;

use pocketmine\block\Block;
use pocketmine\block\Door;
use pocketmine\block\FenceGate;
use pocketmine\block\IronDoor;
use pocketmine\block\IronTrapdoor;
use pocketmine\block\Trapdoor;
use pocketmine\item\ItemBlock;
use pocketmine\math\Vector3;
use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;

use pocketmine\Player;
use pocketmine\scheduler\Task;
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
			"Prevent-Pearling-In-Small-Areas" => false,
			"Prevent-Place-Block-Glitching" => true,
			"Prevent-Break-Block-Glitching" => true,
            "Prevent-Open-Door-Glitching" => true,
			"Prevent-Command-Glitching" => true,
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
				if ($this->config->get("Prevent-Pearling-In-Small-Areas")) {
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

	/**
	 * @priority HIGHEST
	 * @ignoreCancelled False
	 */

	public function onInteract(PlayerInteractEvent $event)
	{
		if ($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) return;
		$player = $event->getPlayer();
		if ($player->isCreative() or $player->isSpectator()) return;
		$block = $event->getBlock();
		if ($event->isCancelled()) {
			if ($block instanceof Door or $block instanceof FenceGate or $block instanceof Trapdoor) {
				if ($this->config->get("Prevent-Open-Door-Glitching")) {
					$dir = $player->getDirection();
					$x = $player->getX();
					$y = $player->getY();
					$z = $player->getZ();
					$pitch = 85;
					$playerX = $player->getX();
					$playerZ = $player->getZ();
					if ($playerX < 0) $playerX = $playerX - 1;
					if ($playerZ < 0) $playerZ = $playerZ - 1;
					if (($block->getX() == (int)$playerX) and ($block->getZ() == (int)$playerZ) and ($player->getY() > $block->getY())) { #If block is under the player
						foreach ($block->getCollisionBoxes() as $blockHitBox) {
							$y = max([$y, $blockHitBox->maxY + 0.01]);
							$pitch = 35;
						}
					} else { #If block is on the side of the player
						if ($dir == 0) {
							foreach ($block->getCollisionBoxes() as $blockHitBox) {
								$x = min([$x, $blockHitBox->minX - 0.31]);
							}
						} elseif ($dir == 1) {
							foreach ($block->getCollisionBoxes() as $blockHitBox) {
								$z = min([$z, $blockHitBox->minZ - 0.31]);
							}
						} elseif ($dir == 2) {
							foreach ($block->getCollisionBoxes() as $blockHitBox) {
								$x = max([$x, $blockHitBox->maxX + 0.31]);
							}
						} elseif ($dir == 3) {
							foreach ($block->getCollisionBoxes() as $blockHitBox) {
								$z = max([$z, $blockHitBox->maxZ + 0.31]);
							}
						}
					}

					if ($this->config->get("CancelOpenDoor-Message")) $player->sendMessage($this->config->get("CancelOpenDoor-Message"));

					$player->teleport(new Vector3($x, $y, $z), $player->getYaw(), $pitch);
				}
			} else {
				if ($this->config->get("Prevent-Place-Block-Glitching")) {
					if ($player->getInventory()->getItemInHand() instanceof ItemBlock and $player->getInventory()->getItemInHand()->getId() !== 0) {
						$playerX = $player->getX();
						$playerZ = $player->getZ();
						if ($playerX < 0) $playerX = $playerX - 1;
						if ($playerZ < 0) $playerZ = $playerZ - 1;
						if (($block->getX() == (int)$playerX) and ($block->getZ() == (int)$playerZ) and ($player->getY() > $block->getY())) { #If block is under the player
							$player->teleport(new Vector3($player->getX(), $player->getY() - 0.3, $player->getZ()));
							if ($this->config->get("CancelBlockPlace-Message")) $player->sendMessage($this->config->get("CancelBlockPlace-Message"));
						}
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
        if ($this->config->get("Prevent-Break-Block-Glitching")) {
            $player = $event->getPlayer();
            $block = $event->getBlock();
			if ($player->isCreative() or $player->isSpectator()) return;
            if ($event->isCancelled()) {
				$dir = $player->getDirection();
				$x = $player->getX();
				$y = $player->getY();
				$z = $player->getZ();
				$playerX = $player->getX();
				$playerZ = $player->getZ();
				if($playerX < 0) $playerX = $playerX - 1;
				if($playerZ < 0) $playerZ = $playerZ - 1;
				if (($block->getX() == (int)$playerX) AND ($block->getZ() == (int)$playerZ) AND ($player->getY() > $block->getY())) { #If block is under the player
					foreach ($block->getCollisionBoxes() as $blockHitBox) {
						$y = max([$y, $blockHitBox->maxY]);
					}
				} else { #If block is on the side of the player
					if ($dir == 0) {
						foreach ($block->getCollisionBoxes() as $blockHitBox) {
							$x = min([$x, $blockHitBox->minX - 0.31]);
						}
					} elseif ($dir == 1) {
						foreach ($block->getCollisionBoxes() as $blockHitBox) {
							$z = min([$z, $blockHitBox->minZ - 0.31]);
						}
					} elseif ($dir == 2) {
						foreach ($block->getCollisionBoxes() as $blockHitBox) {
							$x = max([$x, $blockHitBox->maxX + 0.31]);
						}
					} elseif ($dir == 3) {
						foreach ($block->getCollisionBoxes() as $blockHitBox) {
							$z = max([$z, $blockHitBox->maxZ + 0.31]);
						}
					}
				}
				$player->teleport(new Vector3($x, $y, $z));
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
        if ($this->config->get("Prevent-Place-Block-Glitching")) {
            $player = $event->getPlayer();
			$block = $event->getBlock();
			if ($player->isCreative() or $player->isSpectator()) return;
            if ($event->isCancelled()) {
				$playerX = $player->getX();
				$playerZ = $player->getZ();
				if($playerX < 0) $playerX = $playerX - 1;
				if($playerZ < 0) $playerZ = $playerZ - 1;
				if (($block->getX() == (int)$playerX) AND ($block->getZ() == (int)$playerZ) AND ($player->getY() > $block->getY())) { #If block is under the player
					$player->teleport(new Vector3($player->getX(), $player->getY() - 0.3, $player->getZ()));
					if ($this->config->get("CancelBlockPlace-Message")) $player->sendMessage($this->config->get("CancelBlockPlace-Message"));
				}
            }
        }
    }

	public function onCommandPre(PlayerCommandPreprocessEvent $event){
        if ($this->config->get("Prevent-Command-Glitching")) {
            if((substr($event->getMessage(), 0, 2) == "/ ") || (substr($event->getMessage(), 0, 2) == "/\\") || (substr($event->getMessage(), 0, 2) == "/\"") || (substr($event->getMessage(), -1, 1) === "\\")){
                $event->setCancelled();
                if ($this->config->get("CancelCommand-Message")) {
                    $event->getPlayer()->sendMessage($this->config->get("CancelCommand-Message"));
                }
            }
        }
    }
}