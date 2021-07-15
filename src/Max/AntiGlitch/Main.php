<?php

declare(strict_types=1);

namespace Max\AntiGlitch;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;

use pocketmine\Player;
use pocketmine\utils\Config;
use pocketmine\entity\projectile\EnderPearl;
use pocketmine\level\{Position, Location};

use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\entity\{ProjectileHitEvent, EntityTeleportEvent};
use pocketmine\event\block\{BlockBreakEvent, BlockPlaceEvent};

class Main extends PluginBase implements Listener {

    private $pearlland;

    public function onEnable() {
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder()."config.yml", Config::YAML);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }


    //Fix for glitch: Enderpearling in corners in order to phase through blocks.

    public function onPearlLandBlock(ProjectileHitEvent $event) {
        //$block = $event->getBlockHit();
        $pearl = $event->getEntity();
        $player = $event->getEntity()->getOwningEntity();
        if (!$player instanceof Player) return;
        if (!$pearl instanceof EnderPearl) return;
        $this->pearlland[$player->getName()] = time();
    }

    public function onTP(EntityTeleportEvent $event) {
        $entity = $event->getEntity();
        $level = $entity->getLevel();
        $to = $event->getTo(); //List of phasable blocks:
        $openblocks = [0, 446, 355, 171, 44, 182, 158, 160, 187, 107, 183, 185, 184, 186, 85, 113, 139, 163, 180, 134, 135, 136, 108, 114, 67, 53, 128, 109, 203, 164, 431, 429, 428, 427, 324, 430, 175, 38, 37, 6, 32, 39, 40, 31, 106, 111, 146, 54, 81, 130, 397, 27, 28, 66, 126, 70, 72, 147, 148, 145, 77, 143, 356, 404, 50, 76, 167, 96, 330, 120, 116, 151, 354, 131, 69, 65, 321, 389, 101, 78, 323, 379, 390, 323, 30, 208];  
        if (!isset($this->pearlland[$entity->getName()])) return; //If tp was caused by enderpearl
        if (time() - $this->pearlland[$entity->getName()] > 0.1) return; //If tp was (most likely) caused by enderpearl

        $x = $to->getX();
        $y = $to->getY();
        $z = $to->getZ();
        $xb = $to->getX();
        $yb = $to->getY();
        $zb = $to->getZ();

        if($x < 0) {
            $xb = $x - 1;  //Adjust coords if in a negative quadrant
        } 
        if($z < 0) {
            $zb = $z - 1;  //Adjust coords if in a negative quadrant
        }

        if ($y == ((int)$y) + 0.5) $y = (int)$y; //For slabs


        if (round($x) == $x) { //Pearled on the $x axis
            if(in_array($level->getBlockAt((int)($xb + 0.000001), (int)$y, (int)$zb)->getId(), $openblocks)) {
                $x = $x + 0.3;
                $xb = $xb + 0.000001;
            }
            elseif(in_array($level->getBlockAt((int)($xb - 0.000001), (int)$y, (int)$zb)->getId(), $openblocks)) {
                $x = $x - 0.3;
                $xb = $xb - 0.000001;
            }
        }

        if (round($z) == $z) { //Pearled on the $z axis
            if(in_array($level->getBlockAt((int)$xb, (int)$y, (int)($zb + 0.000001))->getId(), $openblocks)) {
                $z = $z + 0.3;
                $zb = $zb + 0.000001;
            }
            elseif(in_array($level->getBlockAt((int)$xb, (int)$y, (int)($zb - 0.000001))->getId(), $openblocks)) {
                $z = $z - 0.3;
                $zb = $zb - 0.000001;
            }
        }

        if (round($y) == $y) { //Pearled on the $y axis
            if(in_array($level->getBlockAt((int)$xb, (int)($y + 0.000001), (int)$zb)->getId(), $openblocks)) {
                if (!in_array($level->getBlockAt((int)$xb, (int)($y + 1.000001), (int)$zb)->getId(), $openblocks)){
                    if ($this->config->get("PearlGlitching") === true) {
                        if ($this->config->get("CancelPearl-Message") !== false) {
                            $entity->sendMessage($this->config->get("CancelPearl-Message")); //Cancel tp if they throw pearl on the floor and there is a block above (1 block gap)
                        }
                        $event->setCancelled();
                    }
                }
            }
            if(in_array($level->getBlockAt((int)$xb, (int)($y - 0.000001), (int)$zb)->getId(), $openblocks)) $y = $y - 2.0;
        }

        elseif (!in_array($level->getBlockAt((int)$xb, (int)($y + 0.1), (int)$zb)->getId(), $openblocks)) $y = $y - 1.9;  //Determine how far down they should be teleported if there is a block above them:
        elseif (!in_array($level->getBlockAt((int)$xb, (int)($y + 0.2), (int)$zb)->getId(), $openblocks)) $y = $y - 1.8;
        elseif (!in_array($level->getBlockAt((int)$xb, (int)($y + 0.3), (int)$zb)->getId(), $openblocks)) $y = $y - 1.7;
        elseif (!in_array($level->getBlockAt((int)$xb, (int)($y + 0.4), (int)$zb)->getId(), $openblocks)) $y = $y - 1.6;
        elseif (!in_array($level->getBlockAt((int)$xb, (int)($y + 0.5), (int)$zb)->getId(), $openblocks)) $y = $y - 1.5;
        elseif (!in_array($level->getBlockAt((int)$xb, (int)($y + 0.6), (int)$zb)->getId(), $openblocks)) $y = $y - 1.4;
        elseif (!in_array($level->getBlockAt((int)$xb, (int)($y + 0.7), (int)$zb)->getId(), $openblocks)) $y = $y - 1.3;
        elseif (!in_array($level->getBlockAt((int)$xb, (int)($y + 0.8), (int)$zb)->getId(), $openblocks)) $y = $y - 1.2;
        elseif (!in_array($level->getBlockAt((int)$xb, (int)($y + 0.9), (int)$zb)->getId(), $openblocks)) $y = $y - 1.1;
        elseif (!in_array($level->getBlockAt((int)$xb, (int)($y + 1.0), (int)$zb)->getId(), $openblocks)) $y = $y - 1.0;
        elseif (!in_array($level->getBlockAt((int)$xb, (int)($y + 1.1), (int)$zb)->getId(), $openblocks)) $y = $y - 0.9;
        elseif (!in_array($level->getBlockAt((int)$xb, (int)($y + 1.2), (int)$zb)->getId(), $openblocks)) $y = $y - 0.8;
        elseif (!in_array($level->getBlockAt((int)$xb, (int)($y + 1.3), (int)$zb)->getId(), $openblocks)) $y = $y - 0.7;
        elseif (!in_array($level->getBlockAt((int)$xb, (int)($y + 1.4), (int)$zb)->getId(), $openblocks)) $y = $y - 0.6;
        elseif (!in_array($level->getBlockAt((int)$xb, (int)($y + 1.5), (int)$zb)->getId(), $openblocks)) $y = $y - 0.5;
        elseif (!in_array($level->getBlockAt((int)$xb, (int)($y + 1.6), (int)$zb)->getId(), $openblocks)) $y = $y - 0.4;
        elseif (!in_array($level->getBlockAt((int)$xb, (int)($y + 1.7), (int)$zb)->getId(), $openblocks)) $y = $y - 0.3;
        elseif (!in_array($level->getBlockAt((int)$xb, (int)($y + 1.8), (int)$zb)->getId(), $openblocks)) $y = $y - 0.2;
        if (!in_array($level->getBlockAt((int)$xb, (int)($y), (int)$zb)->getId(), $openblocks)) { //Cancel tp if there are blocks in their feet.
            if ($this->config->get("PearlGlitching") === true) {
                if ($this->config->get("CancelPearl-Message") !== false) {
                    $entity->sendMessage($this->config->get("CancelPearl-Message"));
                }
                $event->setCancelled();
            }
        }
        $event->setTo(new Location($x, $y, $z));
    }


    //Fix for glitch: Placing or mining blocks very fast in areas not allowed to in order to get somewhere normally not accessible.

    /**
     * @priority HIGHEST
     * @ignoreCancelled False
     */

    public function fixBlockBreakGlitch(BlockBreakEvent $event) {
        if ($this->config->get("BreakBlockGlitching") === true) {
            $player = $event->getPlayer();
            if ($player->getGamemode() !== 0) return;
            if ($event->isCancelled() == True) {
                $player->teleport(new Position($player->getX(), $player->getY(), $player->getZ(), $player->getLevel()), 1, 1);
                if ($this->config->get("CancelBlockBreak-Message") !== false) {
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
        if ($this->config->get("PlaceBlockGlitching") === true) {
            $player = $event->getPlayer();
            if ($player->getGamemode() !== 0) return;
            if ($event->isCancelled() == True) {
                $player->teleport(new Position($player->getX(), $player->getY(), $player->getZ(), $player->getLevel()), 1, 1);
                if ($this->config->get("CancelBlockPlace-Message") !== false) {
                    $player->sendMessage($this->config->get("CancelBlockPlace-Message"));
                }
            }
        }
    }


    //Fix for glitch: People putting a space before the slash in their commands to evade combat timer.

	public function fixCommandSpace(PlayerCommandPreprocessEvent $event){
        if ($this->config->get("CommandGlitching") === true) {
            if(substr($event->getMessage(), 0, 2) == "/ "){
                $event->setCancelled();
                if ($this->config->get("CancelCommand-Message") !== false) {
                    $event->getPlayer()->sendMessage($this->config->get("CancelCommand-Message"));
                }
            }
        }
    }
}