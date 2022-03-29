<?php

declare(strict_types=1);

namespace Max\AntiGlitch;

use Max\StaffMode\ui\TeleportForm;
use pocketmine\block\Block;
use pocketmine\block\Door;
use pocketmine\block\FenceGate;
use pocketmine\block\IronDoor;
use pocketmine\block\IronTrapdoor;
use pocketmine\block\Trapdoor;
use pocketmine\entity\Entity;
use pocketmine\item\ItemBlock;
use pocketmine\math\Vector3;
use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;

use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\utils\Config;
use pocketmine\entity\projectile\EnderPearl;
use pocketmine\world\{Position, Location};

use pocketmine\event\player\{PlayerCommandPreprocessEvent, PlayerInteractEvent};
use pocketmine\event\entity\{ProjectileHitEvent, EntityTeleportEvent};
use pocketmine\event\block\{BlockBreakEvent, BlockPlaceEvent};

class Main extends PluginBase implements Listener {

    private $pearlland;

    public function onEnable(): void
          {
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder()."config.yml", Config::YAML);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->DefaultConfig = array(
			"Prevent-Pearling-In-Small-Areas" => true,
			"Prevent-Pearling-While-Suffocating" => false,
			"Prevent-Place-Block-Glitching" => false,
			"Prevent-Break-Block-Glitching" => false,
                        "Prevent-Open-Door-Glitching" => false,
			"Prevent-Command-Glitching" => false,
			"CancelPearl-In-Small-Area-Message" => false,
			"CancelPearl-While-Suffocating-Message" => false,
			"CancelBlockPlace-Message" => false,
			"CancelBlockBreak-Message" => false,
                        "CancelOpenDoor-Message" => false,
			"CancelCommand-Message" => "§7[§cGuardian§7] §cCommand Cancelled due to invalid format!"
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
        if ($player instanceof Player && $event->getEntity() instanceof EnderPearl) $this->pearlland[$player->getName()] = $this->getServer()->getTick();
    }

    public function onTP(EntityTeleportEvent $event) {
        $entity = $event->getEntity();
        if (!$entity instanceof Player) return;
        $level = $entity->getWorld();
        $to = $event->getTo();
        if (!isset($this->pearlland[$entity->getName()])) return;
        if ($this->getServer()->getTick() != $this->pearlland[$entity->getName()]) return; //Check if teleportation was caused by enderpearl (by checking is a projectile landed at the same time as teleportation) TODO Find a less hacky way of doing this?

        //Get coords and adjust for negative quadrants.
        $x = $to->getX();
        $y = $to->getY();
        $z = $to->getZ();
        if($x < 0) $x = $x - 1;
        if($z < 0) $z = $z - 1;

        //If pearl is in a block as soon as it lands (which could only mean it was shot into a block over a fence), put it back down in the fence. TODO Find a less hacky way of doing this?
        if($this->isInHitbox($level, $x, $y, $z)) $y = $y - 0.5;

        if ($this->isInHitbox($level, $entity->getX(), $entity->getY() + 1.5, $entity->getZ())) {
			if ($this->config->get("Prevent-Pearling-While-Suffocating")) {
				if ($this->config->get("CancelPearl-While-Suffocating-Message")) {
					$entity->sendMessage($this->config->get("CancelPearl-While-Suffocating-Message"));
				}
				$event->cancel();
				return;
			}
		}

        //Try to find a good place to teleport.
		$ys = $y;
        foreach (range(0, 1.9, 0.05) as $n) {
			$xb = $x;
			$yb = ($ys - $n);
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
				if(isset($level->getBlockAt((int)$xb, (int)$yb, (int)$zb)->getCollisionBoxes()[0])) {
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
				}
				//Prevent pearling into areas too small (configurable in config)
				if ($this->config->get("Prevent-Pearling-In-Small-Areas")) {
					if ($this->config->get("CancelPearl-In-Small-Area-Message")) {
						$entity->sendMessage($this->config->get("CancelPearl-In-Small-Area-Message"));
					}
					$event->cancel();
					return;
				} else {
					if($x < 0) $x = $x + 1;
					if($z < 0) $z = $z + 1;
					$this->getScheduler()->scheduleDelayedTask(new TeleportTask($entity, new Location($x, $y, $z, $entity->getYaw(), $entity->getPitch(), $entity->getLevel())), 5);
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

	public function onInteract(PlayerInteractEvent $event) {
		if ($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) return;
		$player = $event->getPlayer();
		if ($player->isCreative() or $player->isSpectator()) return;
		$block = $event->getBlock();
		if ($event->isCancelled()) {
			if ($block instanceof Door or $block instanceof FenceGate or $block instanceof Trapdoor) {
				if ($this->config->get("Prevent-Open-Door-Glitching")) {
					$x = $player->getPosition()->getX();
					$y = $player->getPosition()->getY();
					$z = $player->getPosition()->getZ();
					$playerX = $player->getPosition()->getX();
					$playerZ = $player->getPosition()->getZ();
					if ($playerX < 0) $playerX = $playerX - 1;
					if ($playerZ < 0) $playerZ = $playerZ - 1;
					if (($block->getX() == (int)$playerX) and ($block->getPosition()->getZ() == (int)$playerZ) and ($player->getPosition()->getY() > $block->getPosition()->getY())) { #If block is under the player
						foreach ($block->getCollisionBoxes() as $blockHitBox) {
							$y = max([$y, $blockHitBox->maxY + 0.05]);
						}
						$player->teleport(new Vector3($x, $y, $z), $player->getPosition()->getYaw(), 35);
					} else { #If block is on the side of the player
						foreach ($block->getCollisionBoxes() as $blockHitBox) {
							if (abs($x - ($blockHitBox->minX + $blockHitBox->maxX) / 2) > abs($z - ($blockHitBox->minZ + $blockHitBox->maxZ) / 2)) {
								$xb = (3 / ($x - ($blockHitBox->minX + $blockHitBox->maxX) / 2)) / 25;
								$zb = 0;
							} else {
								$xb = 0;
								$zb = (3 / ($z - ($blockHitBox->minZ + $blockHitBox->maxZ) / 2)) / 25;
							}
							$player->teleport($player, $player->getLocation()->getYaw(), 85);
							$player->setMotion(new Vector3($xb, 0, $zb));
						}
					}

					if ($this->config->get("CancelOpenDoor-Message")) $player->sendMessage($this->config->get("CancelOpenDoor-Message"));
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
				$x = $player->getPosition()->getX();
				$y = $player->getPosition()->getY();
				$z = $player->getPosition()->getZ();
				$playerX = $player->getPosition()->getX();
				$playerZ = $player->getPosition()->getZ();
				if($playerX < 0) $playerX = $playerX - 1;
				if($playerZ < 0) $playerZ = $playerZ - 1;
				if (($block->getPosition()->getX() == (int)$playerX) AND ($block->getPosition()->getZ() == (int)$playerZ) AND ($player->getPosition()->getY() > $block->getPosition()->getY())) { #If block is under the player
					foreach ($block->getCollisionBoxes() as $blockHitBox) {
						$y = max([$y, $blockHitBox->maxY]);
					}
					$player->teleport(new Vector3($x, $y, $z));
				} else { #If block is on the side of the player
					$xb = 0;
					$zb = 0;
					foreach ($block->getCollisionBoxes() as $blockHitBox) {
						if (abs($x - ($blockHitBox->minX + $blockHitBox->maxX) / 2) > abs($z - ($blockHitBox->minZ + $blockHitBox->maxZ) / 2)) {
							$xb = (5 / ($x - ($blockHitBox->minX + $blockHitBox->maxX) / 2)) / 24;
						} else {
							$zb = (5 / ($z - ($blockHitBox->minZ + $blockHitBox->maxZ) / 2)) / 24;
						}
					}
					$player->setMotion(new Vector3($xb, 0, $zb));
				}
				if ($this->config->get("CancelBlockBreak-Message")) $player->sendMessage($this->config->get("CancelBlockBreak-Message"));
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
				$playerX = $player->getPosition()->getX();
				$playerZ = $player->getPosition()->getZ();
				if($playerX < 0) $playerX = $playerX - 1;
				if($playerZ < 0) $playerZ = $playerZ - 1;
				if (($block->getPosition()->getX() == (int)$playerX) AND ($block->getPosition()->getZ() == (int)$playerZ) AND ($player->getPosition()->getY() > $block->getPosition()->getY())) { #If block is under the player
					$playerMotion = $player->getMotion();
					$this->getScheduler()->scheduleDelayedTask(new MotionTask($player, new Vector3($playerMotion->getPosition()->getX(), -0.1, $playerMotion->getPosition()->getZ())), 2);
					if ($this->config->get("CancelBlockPlace-Message")) $player->sendMessage($this->config->get("CancelBlockPlace-Message"));
				}
            }
        }
    }

	public function onCommandPre(PlayerCommandPreprocessEvent $event){
        if ($this->config->get("Prevent-Command-Glitching")) {
            if((substr($event->getMessage(), 0, 2) == "/ ") || (substr($event->getMessage(), 0, 2) == "/\\") || (substr($event->getMessage(), 0, 2) == "/\"") || (substr($event->getMessage(), -1, 1) === "\\")){
                $event->cancel();
                if ($this->config->get("CancelCommand-Message")) {
                    $event->getPlayer()->sendMessage($this->config->get("CancelCommand-Message"));
                }
            }
        }
    }
}

class TeleportTask extends Task {

	public function __construct(Entity $entity, Location $location) {
		$this->entity = $entity;
		$this->location = $location;
	}

	public function onRun(): void {
		$this->entity->teleport($this->location);
	}
}

class MotionTask extends Task {

	public function __construct(Entity $entity, Vector3 $vector3) {
		$this->entity = $entity;
		$this->vector3 = $vector3;
	}

	public function onRun(): void {
		$this->entity->setMotion($this->vector3);
	}
} 
