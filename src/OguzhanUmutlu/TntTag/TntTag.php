<?php

namespace OguzhanUmutlu\TntTag;

use OguzhanUmutlu\TntTag\arena\Arena;
use OguzhanUmutlu\TntTag\arena\ArenaData;
use OguzhanUmutlu\TntTag\manager\ArenaManager;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use pocketmine\utils\Config;

class TntTag extends PluginBase {
    /*** @var TntTag */
    private static $instance;
    public function onLoad() {
        self::$instance = $this;
    }

    /*** @var Config */
    public $arenaConfig;
    /*** @var string[] */
    public $messages;
    /*** @var ArenaManager */
    public $arenaManager;

    public function onEnable() {
        if(!file_exists($this->getDataFolder()."worlds"))
            mkdir($this->getDataFolder()."worlds");
        $this->arenaConfig = new Config($this->getDataFolder() . "arenas.yml");
        $this->arenaManager = new ArenaManager();
        $this->saveDefaultConfig();
        $this->saveResource("lang/".$this->getConfig()->getNested("lang").".yml");
        $this->messages = (new Config($this->getDataFolder()."lang/".$this->getConfig()->getNested("lang").".yml"))->getAll();
        foreach($this->arenaConfig->getAll() as $arenaData) {
            $data = new ArenaData();
            $data->minPlayer = $arenaData["minPlayer"];
            $data->maxPlayer = $arenaData["maxPlayer"];
            $s = $arenaData["spawn"];
            $data->spawn = new Vector3($s["x"], $s["y"], $s["z"]);
            $data->startingCountdown = $arenaData["startingCountdown"];
            $data->map = $arenaData["map"];
            $data->name = $arenaData["name"];
            $this->arenaManager->createArena(new Arena($data));
        }
        Server::getInstance()->getPluginManager()->registerEvents(new EventListener(), $this);
    }

    /*** @return TntTag|null */
    public static function getInstance(): ?TntTag {
        return self::$instance;
    }

    public static function T(string $key, array $args = []): ?string {
        return str_replace(
            [
                "\\n",
                "{line}",
                "&"
            ],
            [
                "\n",
                "\n",
                "§"
            ],
            str_replace(
                array_map(function($n){return "%".(int)$n;}, array_keys($args)),
                array_values($args),
                self::$instance->messages[$key] ?? "Language error: Key ".$key." not found."
            )
        );
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if($command->getPermission() && !$sender->hasPermission($command->getPermission())) return true;
        switch($command->getName()) {
            case "tnttag":
                switch($args[0] ?? "") {
                    case self::T("args-join"):
                        if(!$sender instanceof Player) {
                            $sender->sendMessage(self::T("use-in-game"));
                            return true;
                        }
                        if($this->arenaManager->getPlayerArena($sender)) {
                            $sender->sendMessage(self::T("already-in-game"));
                            return true;
                        }
                        $arena = $this->arenaManager->getAvailableArena();
                        if(!$arena) {
                            $sender->sendMessage(self::T("no-arena"));
                            return true;
                        }
                        $sender->sendMessage(self::T("redirect", [$arena->getData()->name]));
                        $arena->addPlayer($sender);
                        break;
                    case self::T("args-leave"):
                        if(!$sender instanceof Player) {
                            $sender->sendMessage(self::T("use-in-game"));
                            return true;
                        }
                        if(!$this->arenaManager->getPlayerArena($sender)) {
                            $sender->sendMessage(self::T("not-in-arena"));
                            return true;
                        }
                        $this->arenaManager->getPlayerArena($sender)->removePlayer($sender);
                        $sender->sendMessage(self::T("success-left"));
                        break;
                    default:
                        $sender->sendMessage(self::T("usage", [
                            "/tnttag <".self::T("args-join").", ".self::T("args-leave").">"
                        ]));
                        break;
                }
                break;
            case "tnttagadmin":
                switch($args[0] ?? "") {
                    case "setup":
                        if(!$sender instanceof Player) {
                            $sender->sendMessage("§c> Use this command in-game.");
                            return true;
                        }
                        if(isset(EventListener::$setup[$sender->getName()])) {
                            $sender->sendMessage("§c> You are already in setup mode!");
                            return true;
                        }
                        EventListener::$setup[$sender->getName()] = ["phase" => 1];
                        $sender->sendMessage("§e> Type the name of arena world to chat.");
                        break;
                    case "start":
                        $arena = $sender instanceof Player && $this->arenaManager->getPlayerArena($sender) ? $this->arenaManager->getPlayerArena($sender) : $this->arenaManager->getArenaById((int)($args[0] ?? -1));
                        if(!$arena) {
                            if(!isset($args[0])) {
                                $sender->sendMessage("§c> Usage: /tnttagadmin start <arenaId".">");
                                return true;
                            }
                            $sender->sendMessage("§c> Arena not found.");
                            return true;
                        }
                        $arena->start();
                        $sender->sendMessage("§a> Arena started!");
                        break;
                    case "list":
                        $sender->sendMessage("§a> Arenas:");
                        foreach($this->arenaManager->getArenas() as $arena)
                            $sender->sendMessage("§e> ID: ".$arena->getId().", name: ".$arena->getData()->name.", map: ".$arena->getData()->map.", alive players(".count($arena->getPlayers())."): ".implode(", ", array_map(function($n){return $n->getName();}, $arena->getPlayers())));
                        break;
                    case "private":
                        $arena = $sender instanceof Player && $this->arenaManager->getPlayerArena($sender) ? $this->arenaManager->getPlayerArena($sender) : $this->arenaManager->getArenaById((int)($args[0] ?? -1));
                        if(!$arena) {
                            if(!isset($args[0])) {
                                $sender->sendMessage("§c> Usage: /tnttagadmin private <arenaId".">");
                                return true;
                            }
                            $sender->sendMessage("§c> Arena not found.");
                            return true;
                        }
                        $arena->setPrivate(!$arena->isPrivate());
                        if($arena->isPrivate())
                            $sender->sendMessage("§e> Arena is now §cprivate§e!");
                        else $sender->sendMessage("§e> Arena is now §cpublic§e!");
                        break;
                    default:
                        $sender->sendMessage("§c> Usage: /tnttagadmin <setup, start, list>");
                        break;
                }
                break;
        }
        return true;
    }
}
