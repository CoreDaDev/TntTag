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
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\Player;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\tile\Sign;
use ZipArchive;

class Arena extends Task {
    public const STATUS_ARENA_WAITING = 0;
    public const STATUS_ARENA_STARTING = 1;
    public const STATUS_ARENA_RUNNING = 2;
    public const STATUS_ARENA_CLOSED = 3;

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
    /*** @var ScoreboardManager */
    private $scoreboard;

    public function __construct(ArenaData $data) {
        $this->data = $data;
        $this->id = Utils::getUniqueNumber();
        $this->scoreboard = new ScoreboardManager($this);
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
        $this->setStatus(self::STATUS_ARENA_RUNNING);
        $this->broadcast(self::T("game-started"));
        $this->tagged = null;
        $this->tagging = $this->data->tagCountdown;
        $this->scoreboard->tickScoreboard();
        $this->beforeTag = "";
    }

    private function waitingTick(): void {
        if(count($this->players) >= $this->data->minPlayer) {
            $this->setStatus(self::STATUS_ARENA_STARTING);
            $this->countdown = $this->data->startingCountdown;
            $this->scoreboard->tickScoreboard();
        }
    }

    private function startingTick(): void {
        if(count($this->players) < $this->data->minPlayer)
            $this->setStatus(self::STATUS_ARENA_WAITING);
        else if($this->countdown <= 0) {
            $this->start();
        } else {
            foreach($this->players as $player) {
                $player->sendTitle("??r", self::T("starts-in", [$this->countdown]), 0, 20, 0);
                $player->level->addSound(new ClickSound($player), [$player]);
            }
            $this->countdown--;
            $this->scoreboard->tickScoreboard();
        }
    }

    private function runningTick(): void {
        if(count($this->players) <= 1) {
            $this->stop();
            return;
        }
        if(!$this->tagged) {
            foreach($this->players as $player)
                $player->sendTitle("??r", self::T("tag-in", [$this->tagging]), 0, 20, 0);
            $this->tagging--;
            $this->scoreboard->tickScoreboard();
            if($this->tagging <= 0 && !empty($this->players)) {
                $this->tagged = $this->players[array_rand($this->players)];
                $this->beforeTag = $this->tagged->getNameTag();
                $this->tagged->setNameTag(self::T("nametag", [$this->beforeTag]));
                $this->tagging = $this->data->tntCountdown;
                $this->scoreboard->tickScoreboard();
                TntTag::getInstance()->getScheduler()->scheduleRepeatingTask(new TagTask($this->tagged, $this), 15);
                $this->tagged->getInventory()->setContents(array_map(function($a) {$tnt = Item::get(Item::TNT);$tnt->setNamedTagEntry(new ListTag(Item::TAG_ENCH, [], NBT::TAG_Compound));return $tnt;}, $this->tagged->getInventory()->getContents(true)));
            }
        } else {
            $this->tagging--;
            $this->scoreboard->tickScoreboard();
            if($this->tagging <= 0) {
                $this->setDead($this->tagged);
                $this->tagged->level->addParticle(new HugeExplodeParticle($this->tagged));
                $pk = new PlaySoundPacket();
                $pk->pitch = 0;
                $pk->soundName = "";
                $pk->volume = 100;
                $pk->x = $this->tagged->x;
                $pk->y = $this->tagged->y;
                $pk->z = $this->tagged->z;
                $this->tagged->level->broadcastPacketToViewers($this->tagged, $pk);
                $this->tagged->setNameTag($this->beforeTag);
                $this->tagged = null;
                $this->tagging = $this->data->tagCountdown;
                $this->scoreboard->tickScoreboard();
            } else if($this->tagging <= 10) {
                foreach($this->players as $player)
                    $player->sendTitle("??r", self::T("explode-in", [$this->tagging]), 0, 20, 0);
            }
        }
    }

    public function setDead(Player $player): void {
        $player->setGamemode(3);
        $bed = Item::get(Item::BED)->setCustomName(self::T("leave-item"));
        $bed->getNamedTag()->setString("tntTagBed", "true");
        $player->getInventory()->setItem(8, $bed);
        $player->sendTitle("??r", self::T("died-title"), 0, 20, 0);
        $this->broadcast(self::T("died-message", [$player->getName()]));
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        (new TntTagLoseEvent($player, $this))->call();
        unset($this->players[$player->getName()]);
        $this->scoreboard->removePlayer($player);
        $this->scoreboard->tickScoreboard();
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
                $player->sendTitle("??r", self::T("won-title"), 0, 60, 0);
            }
        $this->removeWorld();
        $this->createWorld();
        $this->private = false;
        $this->players = [];
        $this->setStatus(self::STATUS_ARENA_WAITING);
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
        $this->setStatus(self::STATUS_ARENA_CLOSED);
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
        $this->setStatus(self::STATUS_ARENA_WAITING);
    }

    public function removeWorld(): void {
        $this->setStatus(self::STATUS_ARENA_CLOSED);
        $level = $this->getLevel();
        if($level instanceof Level) {
            foreach($level->getPlayers() as $player) {
                $player->setGamemode(0);
                $this->scoreboard->removePlayer($player);
            }
            $this->getServer()->unloadLevel($level);
        }
        Utils::removeDirectory($this->getWorldPath());
    }

    public function getServer(): Server {
        return Server::getInstance();
    }

    /*** @param int $status */
    public function setStatus(int $status): void {
        $this->status = $status;
        $this->scoreboard->tickScoreboard();
        $this->tickJoinSign();
    }

    public function tickJoinSign(): void {
        $join = $this->data->joinSign;
        if(!$join->level instanceof Level) return;
        $tile = $join->level->getTile($join);
        if(!$tile instanceof Sign) return;
        $color = [
            self::STATUS_ARENA_WAITING => "??7",
            self::STATUS_ARENA_STARTING => "??a",
            self::STATUS_ARENA_RUNNING => "??c",
            self::STATUS_ARENA_CLOSED => "??1",
        ][$this->status];
        $status = [
            self::STATUS_ARENA_WAITING => "Waiting",
            self::STATUS_ARENA_STARTING => "Starting",
            self::STATUS_ARENA_RUNNING => "Running",
            self::STATUS_ARENA_CLOSED => "Ending",
        ][$this->status];
        if($this->status == self::STATUS_ARENA_STARTING && count($this->players) >= $this->data->maxPlayer) {
            $color = "??6";
            $status = "Full";
        }
        $tile->setText("??a[TntTag]", "??eStatus: $color$status", "??d" . $this->data->name, "$color" . count($this->players) ." / " . $this->data->maxPlayer);
    }

    public function addPlayer(Player $player): void {
        if(isset($this->players[$player->getName()])) return;
        $this->scoreboard->addPlayer($player);
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
                    $p->sendPopup(self::T("join-popup", [$this->data->minPlayer-count($this->getPlayers())]));
        $this->scoreboard->tickScoreboard();
        $this->tickJoinSign();
    }

    public function removePlayer(Player $player): void {
        if(!isset($this->players[$player->getName()])) return;
        $player->getInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->removeAllEffects();
        $player->teleport($this->getServer()->getDefaultLevel()->getSpawnLocation());
        if($this->tagged && $this->tagged->getName() == $player->getName())
            $this->tagged = null;
        $player->setNameTag($this->beforeTag);
        unset($this->players[$player->getName()]);
        $this->broadcast(self::T("left-message", [$player->getName()]));
        $this->scoreboard->removePlayer($player);
        $this->scoreboard->tickScoreboard();
        $this->tickJoinSign();
    }

    public function broadcast(string $message): void {
        foreach($this->players as $player)
            $player->sendMessage($message);
    }

    /*** @return int */
    public function getStatus(): int {
        return $this->status;
    }

    /*** @return int */
    public function getCountdown(): int {
        return $this->countdown;
    }
}
