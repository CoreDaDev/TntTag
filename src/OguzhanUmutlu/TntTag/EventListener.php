<?php

namespace OguzhanUmutlu\TntTag;

use OguzhanUmutlu\TntTag\arena\Arena;
use OguzhanUmutlu\TntTag\arena\ArenaData;
use OguzhanUmutlu\TntTag\arena\TagTask;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\inventory\InventoryPickupArrowEvent;
use pocketmine\event\inventory\InventoryPickupItemEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ListTag;
use pocketmine\Player;
use pocketmine\Server;

class EventListener implements Listener {
    public static $setup = [];
    public function onPlayerChat(PlayerChatEvent $event) {
        $player = $event->getPlayer();
        if(!isset(self::$setup[$player->getName()])) return;
        switch(self::$setup[$player->getName()]["phase"]) {
            case 1:
                $level = Server::getInstance()->getLevelByName($event->getMessage());
                if(!$level) {
                    $player->sendMessage("§c> World not found!");
                    return;
                }
                self::$setup[$player->getName()]["map"] = $level->getFolderName();
                self::$setup[$player->getName()]["phase"] = 2;
                $player->teleport($level->getSpawnLocation());
                $player->setGamemode(3);
                $player->sendMessage("§a> World set to §b".$level->getFolderName()."§a!");
                $player->sendMessage("§e> Type the name of the arena to chat.");
                break;
            case 2:
                self::$setup[$player->getName()]["name"] = $event->getMessage();
                self::$setup[$player->getName()]["phase"] = 3;
                $player->sendMessage("§a> Name set to §b".$event->getMessage()."§a!");
                $player->sendMessage("§e> Type the minimum player amount of the arena to chat.");
                break;
            case 3:
                if(!is_numeric($event->getMessage()) || (int)$event->getMessage() <= 0) {
                    $player->sendMessage("§c> Minimum player amount should be numeric and positive. (default = 4)");
                    return;
                }
                self::$setup[$player->getName()]["minPlayer"] = (int)$event->getMessage();
                self::$setup[$player->getName()]["phase"] = 4;
                $player->sendMessage("§a> Minimum player amount set to §b".(int)$event->getMessage()."§a!");
                $player->sendMessage("§e> Type the maximum player amount of the arena to chat.");
                break;
            case 4:
                if(!is_numeric($event->getMessage()) || (int)$event->getMessage() <= 0) {
                    $player->sendMessage("§c> Maximum player amount should be numeric and positive. (default = 16)");
                    return;
                }
                self::$setup[$player->getName()]["maxPlayer"] = (int)$event->getMessage();
                self::$setup[$player->getName()]["phase"] = 5;
                $player->sendMessage("§a> Maximum player amount set to §b".(int)$event->getMessage()."§a!");
                $player->sendMessage("§e> Type the tag countdown of the arena to chat.");
                break;
            case 5:
                if(!is_numeric($event->getMessage()) || (int)$event->getMessage() <= 0) {
                    $player->sendMessage("§c> Tag countdown should be numeric and positive. (default = 15)");
                    return;
                }
                self::$setup[$player->getName()]["tagCountdown"] = (int)$event->getMessage();
                self::$setup[$player->getName()]["phase"] = 6;
                $player->sendMessage("§a> Tag countdown set to §b".(int)$event->getMessage()."§a!");
                $player->sendMessage("§e> Type the tnt countdown of the arena to chat.");
                break;
            case 6:
                if(!is_numeric($event->getMessage()) || (int)$event->getMessage() <= 0) {
                    $player->sendMessage("§c> Tnt countdown should be numeric and positive. (default = 15)");
                    return;
                }
                self::$setup[$player->getName()]["tntCountdown"] = (int)$event->getMessage();
                self::$setup[$player->getName()]["phase"] = 7;
                $player->sendMessage("§a> Tnt countdown set to §b".(int)$event->getMessage()."§a!");
                $player->sendMessage("§e> Type the starting time of the arena to chat.");
                break;
            case 7:
                if(!is_numeric($event->getMessage()) || (int)$event->getMessage() <= 0) {
                    $player->sendMessage("§c> Starting time should be numeric and positive. (default = 10)");
                    return;
                }
                self::$setup[$player->getName()]["startingCountdown"] = (int)$event->getMessage();
                self::$setup[$player->getName()]["phase"] = 8;
                $player->sendMessage("§a> Starting time set to §b".(int)$event->getMessage()."§a!");
                $player->sendMessage("§e> Break the block that players will spawn on it.");
                break;
            case 9:
                switch($event->getMessage()) {
                    case "yes":
                        $setup = self::$setup[$player->getName()];
                        if(!isset($setup["map"]) || !Server::getInstance()->getLevelByName($setup["map"])) {
                            $player->sendMessage("§c> Missing world.");
                            return;
                        }
                        if(!isset($setup["name"])) {
                            $player->sendMessage("§c> Missing name.");
                            return;
                        }
                        if(!isset($setup["minPlayer"])) {
                            $player->sendMessage("§c> Missing minimum player.");
                            return;
                        }
                        if(!isset($setup["maxPlayer"])) {
                            $player->sendMessage("§c> Missing maximum player.");
                            return;
                        }
                        if(!isset($setup["tagCountdown"])) {
                            $player->sendMessage("§c> Missing tag countdown.");
                            return;
                        }
                        if(!isset($setup["tntCountdown"])) {
                            $player->sendMessage("§c> Missing tnt countdown.");
                            return;
                        }
                        if(!isset($setup["startingCountdown"])) {
                            $player->sendMessage("§c> Missing starting time.");
                            return;
                        }
                        if(!($setup["spawn"] ?? null) instanceof Vector3) {
                            $player->sendMessage("§c> Missing spawn.");
                            return;
                        }
                        $data = new ArenaData();
                        $data->minPlayer = $setup["minPlayer"];
                        $data->maxPlayer = $setup["maxPlayer"];
                        $data->spawn = $setup["spawn"];
                        $data->startingCountdown = $setup["startingCountdown"];
                        $data->tntCountdown = $setup["tntCountdown"];
                        $data->tagCountdown = $setup["tagCountdown"];
                        $data->map = $setup["map"];
                        $data->name = $setup["name"];
                        TntTag::getInstance()->arenaManager->createArena(new Arena($data), true);
                        $player->setGamemode(0);
                        unset(self::$setup[$player->getName()]);
                        $player->sendMessage("§a> Arena created.");
                        break;
                    case "no":
                        unset(self::$setup[$player->getName()]);
                        $player->sendMessage("§c> Setup data removed.");
                        break;
                    default:
                        $player->sendMessage("§e> Do you want to create arena? (y, n)");
                        break;
                }
                break;
            default:
                return;
        }
        $event->setCancelled();
    }
    public function onBreakEv(BlockBreakEvent $event) {
        $player = $event->getPlayer();
        if(!isset(self::$setup[$player->getName()])) return;
        if(self::$setup[$player->getName()]["phase"] == 8) {
            $a = $event->getBlock()->floor()->add(0, 1);
            self::$setup[$player->getName()]["spawn"] = $a;
            self::$setup[$player->getName()]["phase"] = 9;
            $player->sendMessage("§a> Spawn set to §bX: ".$a->x.", Y: ".$a->y.", Z: ".$a->z."§a!");
            $player->sendMessage("§e> Do you want to create arena? (yes, no)");
        }
    }

    // ARENA LISTENER

    public function onBreak(BlockBreakEvent $event) {
        $player = $event->getPlayer();
        if(!$player instanceof Player) return;
        $arena = TntTag::getInstance()->arenaManager->getPlayerArena($player);
        if(!$arena instanceof Arena) return;
        $event->setCancelled();
    }

    public function onMove(PlayerMoveEvent $event) {
        $player = $event->getPlayer();
        if(!$player instanceof Player) return;
        $arena = TntTag::getInstance()->arenaManager->getPlayerArena($player);
        if(!$arena instanceof Arena) return;
        $player->setFood(20);
        $player->setSaturation(20);
    }

    public function onPlace(BlockPlaceEvent $event) {
        $player = $event->getPlayer();
        if(!$player instanceof Player) return;
        $arena = TntTag::getInstance()->arenaManager->getPlayerArena($player);
        if(!$arena instanceof Arena) return;
        $event->setCancelled();
    }

    public function onInteract(PlayerInteractEvent $event) {
        $player = $event->getPlayer();
        if(!$player instanceof Player) return;
        $arena = TntTag::getInstance()->arenaManager->getPlayerArena($player);
        if(!$arena instanceof Arena) {
            if($event->getItem()->getNamedTag()->hasTag("tntTagBed")) {
                $player->teleport(Server::getInstance()->getDefaultLevel()->getSpawnLocation());
                $player->getInventory()->clearAll();
                $player->setGamemode(0);
            }
            return;
        }
        $event->setCancelled();
    }

    public function onDamage(EntityDamageEvent $event) {
        $player = $event->getEntity();
        if(!$player instanceof Player) return;
        $arena = TntTag::getInstance()->arenaManager->getPlayerArena($player);
        if(!$arena instanceof Arena) return;
        if(!$event instanceof EntityDamageByEntityEvent) {
            $event->setCancelled();
            if($event->getCause() == EntityDamageEvent::CAUSE_VOID)
                $arena->setDead($player);
            return;
        }
        $damagePlayer = $event->getDamager();
        if(!$damagePlayer instanceof Player || !($damageArena = TntTag::getInstance()->arenaManager->getPlayerArena($damagePlayer)) instanceof Arena || $damageArena->getId() != $arena->getId() || !$arena->tagged || $arena->tagged->getName() != $damagePlayer->getName()) {
            $event->setCancelled();
            return;
        }
        if(!$arena->tagged->isClosed()) {
            $arena->tagged->getInventory()->clearAll();
            $arena->tagged->getCursorInventory()->clearAll();
            $arena->tagged->getArmorInventory()->clearAll();
            $arena->tagged->setNameTag($arena->beforeTag);
        }
        $arena->tagged = $player;
        $arena->beforeTag = $player->getNameTag();
        TntTag::getInstance()->getScheduler()->scheduleRepeatingTask(new TagTask($arena->tagged, $arena), 15);
        $player->setNameTag(TntTag::T("nametag", [$player->getNameTag()]));
        $player->getInventory()->setContents(array_map(function($a) {$tnt = Item::get(Item::TNT);$tnt->setNamedTagEntry(new ListTag(Item::TAG_ENCH, [], NBT::TAG_Compound));return $tnt;}, $player->getInventory()->getContents(true)));
    }

    public function onDropItem(PlayerDropItemEvent $event) {
        $player = $event->getPlayer();
        $arena = TntTag::getInstance()->arenaManager->getPlayerArena($player);
        if(!$arena instanceof Arena) {
            if($event->getItem()->getNamedTag()->hasTag("tntTagBed"))
                $event->setCancelled();
            return;
        }
        if(isset($arena->getPlayers()[$player->getName()])) $event->setCancelled();
    }

    public function onTakeItem(InventoryPickupItemEvent $event) {
        foreach($event->getViewers() as $player) {
            $arena = $player instanceof Player ? TntTag::getInstance()->arenaManager->getPlayerArena($player) : null;
            if ($arena instanceof Arena && $player instanceof Player && isset($arena->getPlayers()[$player->getName()])) $event->setCancelled();
        }
    }

    public function onTakeArrow(InventoryPickupArrowEvent $event) {
        foreach($event->getViewers() as $player) {
            $arena = $player instanceof Player ? TntTag::getInstance()->arenaManager->getPlayerArena($player) : null;
            if ($arena instanceof Arena && $player instanceof Player && isset($arena->getPlayers()[$player->getName()])) $event->setCancelled();
        }
    }

    public function onQuit(PlayerQuitEvent $event) {
        $player = $event->getPlayer();
        $arena = TntTag::getInstance()->arenaManager->getPlayerArena($player);
        if(!$arena instanceof Arena) return;
        if(!isset($arena->getPlayers()[$player->getName()])) return;
        $arena->removePlayer($player);
    }
}
