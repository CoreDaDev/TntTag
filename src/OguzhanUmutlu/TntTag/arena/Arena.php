<?php

namespace OguzhanUmutlu\TntTag\arena;

use Exception;
use OguzhanUmutlu\TntTag\events\TntTagLoseEvent;
use OguzhanUmutlu\TntTag\events\TntTagWinEvent;
use OguzhanUmutlu\TntTag\TntTag;
use OguzhanUmutlu\TntTag\utils\Utils;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\level\particle\HugeExplodeParticle;
use pocketmine\level\Position;
use pocketmine\level\sound\ClickSound;
use pocketmine\level\sound\LaunchSound;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ListTag;
use pocketmine\Player;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use ZipArchive;

class Arena extends Task {
    public const STATUS_ARENA_WAITING = 0;
    public const STATUS_ARENA_STARTING = 1;
    public const STATUS_ARENA_RUNNING = 2;
    public const STATUS_ARENA_SETUP = 3;

    private $private = false;
    /*** @var Player[] */
    private $players = [];
    private $status = self::STATUS_ARENA_WAITING;
    private $countdown = 0;
    public $beforeTag = "";
    /*** @var Player|null */
    public $tagged = null;
    private $tagging = 15;

    /*** @var ArenaData */
    private $data;
    private $id;

    public function __construct(ArenaData $data) {
        $this->data = $data;
        $this->id = Utils::getUniqueNumber();
    }

    public function initArena(): void {
        TntTag::getInstance()->getScheduler()->scheduleRepeatingTask($this, 20);
        $this->removeWorld();
        $this->createWorld();
    }

    public function getLevel(): ?Level {
        return $this->getServer()->getLevelByName($this->data->map);
    }

    public static function T($key,$args=[]): ?string {return TntTag::T($key,$args);}

    public function start(): void {
        $this->status = self::STATUS_ARENA_RUNNING;
        $this->broadcast(self::T("game-started"));
        $this->tagged = null;
        $this->tagging = 15;
        $this->beforeTag = "";
    }

    private function waitingTick(): void {
        if(count($this->players) >= $this->data->minPlayer) {
            $this->status = self::STATUS_ARENA_STARTING;
            $this->countdown = $this->data->startingCountdown;
        }
    }

    private function startingTick(): void {
        if(count($this->players) < $this->data->minPlayer)
            $this->status = self::STATUS_ARENA_WAITING;
        else if($this->countdown <= 0) {
            $this->start();
        } else {
            foreach($this->players as $player) {
                $player->sendTitle("§r", self::T("starts-in", [$this->countdown]), 0, 20, 0);
                $player->level->addSound(new ClickSound($player), [$player]);
            }
            $this->countdown--;
        }
    }

    private function runningTick(): void {
        if(count($this->players) <= 1) {
            $this->stop();
            return;
        }
        if(!$this->tagged) {
            foreach($this->players as $player)
                $player->sendTitle("§r", self::T("tag-in", [$this->tagging]), 0, 20, 0);
            $this->tagging--;
            if($this->tagging <= 0 && !empty($this->players)) {
                $this->tagged = $this->players[array_rand($this->players)];
                $this->beforeTag = $this->tagged->getNameTag();
                $this->tagged->setNameTag(self::T("nametag", [$this->beforeTag]));
                $this->tagging = 15;
                TntTag::getInstance()->getScheduler()->scheduleRepeatingTask(new TagTask($this->tagged, $this), 15);
                $this->tagged->getInventory()->setContents(array_map(function($a) {$tnt = Item::get(Item::TNT);$tnt->setNamedTagEntry(new ListTag(Item::TAG_ENCH, [], NBT::TAG_Compound));return $tnt;}, $this->tagged->getInventory()->getContents(true)));
            }
        } else {
            $this->tagging--;
            if($this->tagging <= 0) {
                $this->setDead($this->tagged);
                $this->tagged->level->addParticle(new HugeExplodeParticle($this->tagged));
                $this->tagged->level->addSound(new LaunchSound($this->tagged));
                $this->tagged->setNameTag($this->beforeTag);
                $this->tagged = null;
            } else if($this->tagging <= 10) {
                foreach($this->players as $player)
                    $player->sendTitle("§r", self::T("explode-in", [$this->tagging]), 0, 20, 0);
            }
        }
    }

    public function setDead(Player $player): void {
        $player->setGamemode(3);
        $bed = Item::get(Item::BED)->setCustomName(self::T("leave-item"));
        $bed->getNamedTag()->setString("tntTagBed", "true");
        $player->getInventory()->setItem(8, $bed);
        $player->sendTitle("§r", self::T("died-title"), 0, 20, 0);
        $this->broadcast(self::T("died-message", [$player->getName()]));
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        (new TntTagLoseEvent($player, $this))->call();
        unset($this->players[$player->getName()]);
    }

    /*** @return bool */
    public function isPrivate(): bool {
        return $this->private;
    }

    /*** @param bool $private */
    public function setPrivate(bool $private): void {
        $this->private = $private;
    }

    private function stop(bool $force = false) {
        if(!$force)
            foreach($this->players as $player) {
                (new TntTagWinEvent($player, $this))->call();
                $player->sendTitle("§r", self::T("won-title"), 0, 60, 0);
            }
        $this->removeWorld();
        $this->createWorld();
        $this->private = false;
        $this->players = [];
        $this->status = self::STATUS_ARENA_WAITING;
    }

    public function onRun(int $currentTick) {
        try {
            switch($this->status) {
                case self::STATUS_ARENA_WAITING:
                    $this->waitingTick();
                    break;
                case self::STATUS_ARENA_STARTING:
                    $this->startingTick();
                    break;
                case self::STATUS_ARENA_RUNNING:
                    $this->runningTick();
                    break;
            }
        } catch(Exception $exception) {
            $this->stop(true);
            $fileName = TntTag::getInstance()->getDataFolder()."crashes/".(int)microtime(true).".txt";
            if(!file_exists(TntTag::getInstance()->getDataFolder()."crashes"))
                mkdir(TntTag::getInstance()->getDataFolder()."crashes");
            file_put_contents($fileName,
                "You can report this error here: https://github.com/OguzhanUmutlu/TntTag/issues".
                "\nException timestamp: " . microtime(true).
                "\nException date: " . date(DATE_ATOM).
                "\nException message: " . $exception->getMessage().
                "\nException code: " . $exception->getCode().
                "\nException file: " . $exception->getFile().
                "\nException line: " . $exception->getLine().
                "\nException previous: " . $exception->getPrevious().
                "\nException trace: " . $exception->getTraceAsString());
            TntTag::getInstance()->getLogger()->warning($this->data->name . " arena crashed! You can report error in this file: ".$fileName);
            TntTag::getInstance()->getLogger()->warning("To report bugs: https://github.com/OguzhanUmutlu/TntTag/issues");
        }
    }

    /*** @return ArenaData */
    public function getData(): ArenaData {
        return $this->data;
    }

    /*** @return Player[] */
    public function getPlayers(): array {
        return $this->players;
    }

    /*** @return int */
    public function getId(): int {
        return $this->id;
    }

    public function getWorldPath(): string {
        return $this->getServer()->getDataPath()."worlds/".$this->data->map;
    }

    public function getWorldDataPath(): string {
        return TntTag::getInstance()->getDataFolder()."worlds/".$this->data->map.".zip";
    }

    public function createWorld(): void {
        $this->status = self::STATUS_ARENA_SETUP;
        if(!file_exists($this->getWorldDataPath())) {
            $this->stop(true);
            TntTag::getInstance()->getLogger()->warning($this->data->name . " arena crashed! Error: Saved zip not found.");
            TntTag::getInstance()->getLogger()->warning("To report bugs: https://github.com/OguzhanUmutlu/TntTag/issues");
            return;
        }
        $zipArchive = new ZipArchive();
        $zipArchive->open($this->getWorldDataPath());
        $zipArchive->extractTo($this->getServer()->getDataPath()."worlds");
        $zipArchive->close();
        $this->getServer()->loadLevel($this->data->map);
        $this->status = self::STATUS_ARENA_WAITING;
    }

    public function removeWorld(): void {
        $this->status = self::STATUS_ARENA_SETUP;
        $level = $this->getLevel();
        if($level instanceof Level) {
            foreach($level->getPlayers() as $player)
                $player->setGamemode(0);
            $this->getServer()->unloadLevel($level);
        }
        Utils::removeDirectory($this->getWorldPath());
    }

    public function getServer(): Server {
        return Server::getInstance();
    }

    public function addPlayer(Player $player): void {
        if(isset($this->players[$player->getName()])) return;
        $this->players[$player->getName()] = $player;
        $player->teleport(Position::fromObject($this->data->spawn, $this->getLevel()));
        $player->getInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->setGamemode(2);
        $this->broadcast(self::T("join-message", [$player->getName()]));
        if(count($this->getPlayers()) < $this->data->minPlayer)
            foreach($this->getPlayers() as $p)
                if($p->getId() != $player->getId())
                    $p->sendPopup(self::T("join-popup", [$this->data-count($this->getPlayers())]));
    }

    public function removePlayer(Player $player): void {
        if(!isset($this->players[$player->getName()])) return;
        $player->getInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        if($this->tagged && $this->tagged->getName() == $player->getName())
            $this->tagged = null;
        $player->setNameTag($this->beforeTag);
        unset($this->players[$player->getName()]);
        $this->broadcast(self::T("left-message", [$player->getName()]));
    }

    public function broadcast(string $message): void {
        foreach($this->players as $player)
            $player->sendMessage($message);
    }

    /*** @return int */
    public function getStatus(): int {
        return $this->status;
    }
}
