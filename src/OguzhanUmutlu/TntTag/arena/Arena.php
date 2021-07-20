<?php

namespace OguzhanUmutlu\BlockHunt\arena;

use Exception;
use OguzhanUmutlu\BlockHunt\entities\BlockHuntEntity;
use OguzhanUmutlu\BlockHunt\events\BlockHuntLoseEvent;
use OguzhanUmutlu\BlockHunt\events\BlockHuntWinEvent;
use OguzhanUmutlu\BlockHunt\BlockHunt;
use OguzhanUmutlu\BlockHunt\utils\Utils;
use pocketmine\block\Block;
use pocketmine\entity\Effect;
use pocketmine\entity\EffectInstance;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\level\Position;
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
    private $hunters = [];
    /*** @var Player[] */
    private $seekers = [];
    private $status = self::STATUS_ARENA_WAITING;
    private $countdown = 0;
    /*** @var int[] */
    public $freeze = [];
    /*** @var BlockHuntEntity[] */
    public $blocks = [];
    /*** @var int[] */
    public $lastTransform = [];
    /*** @var int[][] */
    public $ids = [];

    /*** @var ArenaData */
    private $data;
    private $id;

    public function __construct(ArenaData $data) {
        $this->data = $data;
        $this->id = Utils::getUniqueNumber();
    }

    public function initArena(): void {
        BlockHunt::getInstance()->getScheduler()->scheduleRepeatingTask($this, 20);
        $this->removeWorld();
        $this->createWorld();
    }

    public function getLevel(): ?Level {
        return $this->getServer()->getLevelByName($this->data->map);
    }

    public static function T($key,$args=[]): ?string {return BlockHunt::T($key,$args);}

    /**
     * @throws Exception
     */
    public function start(): void {
        $this->status = self::STATUS_ARENA_RUNNING;
        $this->broadcast(self::T("game-started"));
        foreach($this->getPlayers() as $player)
            $player->teleport($this->data->spawn);
        foreach($this->hunters as $player) {
            $this->freeze[$player->getName()] = time()+15;
            $player->addEffect(new EffectInstance(Effect::getEffect(Effect::BLINDNESS), 300, 255, false));
        }
        foreach($this->seekers as $player) {
            $this->createSeekerBlock($player);
        }
        $this->countdown = 0;
    }

    /**
     * @throws Exception
     */
    public function createSeekerBlock(Player $player, ?int $id = null, ?int $meta = null) {
        if(!$id) $id = isset($this->ids[$player->getName()]) ? $this->ids[$player->getName()][0] : 1;
        if(!$meta) $meta = isset($this->ids[$player->getName()]) ? $this->ids[$player->getName()][1] : 0;
        if(($this->blocks[$player->getName()] ?? null) instanceof Block)
            $this->getLevel()->setBlock($this->blocks[$player->getName()], Block::get(0));
        else if(($this->blocks[$player->getName()] ?? null) instanceof BlockHuntEntity && !$this->blocks[$player->getName()]->isFlaggedForDespawn())
            return;
        $this->ids[$player->getName()] = [$id, $meta];
        if(!isset(BlockHunt::getInstance()->hide[$player->getName()]))
            BlockHunt::getInstance()->hide[$player->getName()] = [];
        foreach($this->hunters as $p)
            if($p instanceof Player && !$p->isClosed()) {
                $p->hidePlayer($player);
                BlockHunt::getInstance()->hide[$player->getName()][$p->getName()] = $p;
            }
        $nbt = BlockHuntEntity::createBaseNBT($player->asVector3());
        $nbt->setInt("TileID", $id);
        $nbt->setByte("Data", $meta);
        $entity = BlockHuntEntity::createEntity("BlockHuntEntity", $player->level, $nbt);
        if($entity instanceof BlockHuntEntity) {
            $this->blocks[$player->getName()] = $entity;
            $entity->player = $player;
            $entity->arena = $this;
            $entity->spawnToAll();
            if($entity->isFlaggedForDespawn())
                throw new Exception("Some why entity did de-spawned.");
        } else throw new Exception(BlockHuntEntity::class . " expected, ".get_class($entity) . " provided");
    }

    private function waitingTick(): void {
        if(count($this->getPlayers()) >= $this->data->minPlayer) {
            $this->status = self::STATUS_ARENA_STARTING;
            $this->countdown = $this->data->startingCountdown;
        }
    }

    /**
     * @throws Exception
     */
    private function startingTick(): void {
        if(count($this->getPlayers()) < $this->data->minPlayer)
            $this->status = self::STATUS_ARENA_WAITING;
        else if($this->countdown <= 0) {
            $this->start();
        } else {
            foreach($this->getPlayers() as $player)
                $player->sendTitle("§r", self::T("starts-in", [$this->countdown]), 0, 20, 0);
            $this->countdown--;
        }
    }

    private function runningTick(): void {
        if(count($this->seekers) <= 0 || count($this->hunters) <= 0 || $this->countdown >= $this->getData()->maxTime)
            $this->stop();
        $this->countdown++;
    }

    /*** @return int */
    public function getCountdown(): int {
        return $this->countdown;
    }

    /*** @return bool */
    public function isPrivate(): bool {
        return $this->private;
    }

    /*** @param bool $private */
    public function setPrivate(bool $private): void {
        $this->private = $private;
    }

    public function getWinners(): array {
        if(empty($this->seekers))
            return $this->hunters;
        if(empty($this->hunters))
            return [];
        return $this->seekers;
    }

    public function getWinnerString(): string {
        if(empty($this->seekers))
            return "hunters";
        if(empty($this->hunters))
            return "no-one";
        return "seekers";
    }

    private function stop(bool $force = false) {
        if(!$force) {
            foreach($this->getWinners() as $player)
                (new BlockHuntWinEvent($player, $this))->call();
            foreach($this->getPlayers() as $player)
                $player->sendTitle("§r", self::T("won-title", [self::T($this->getWinnerString())]), 0, 60, 0);
        }
        foreach($this->getPlayers() as $player) {
            foreach((BlockHunt::getInstance()->hide[$player->getName()] ?? []) as $p)
                if($p instanceof Player && !$p->isClosed()) {
                    $p->showPlayer($player);
                    unset(BlockHunt::getInstance()->hide[$player->getName()][$p->getName()]);
                }
        }
        $this->removeWorld();
        $this->createWorld();
        $this->private = false;
        $this->hunters = [];
        $this->seekers = [];
        $this->blocks = [];
        $this->freeze = [];
        $this->lastTransform = [];
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
            $fileName = BlockHunt::getInstance()->getDataFolder()."crashes/".(int)microtime(true).".txt";
            if(!file_exists(BlockHunt::getInstance()->getDataFolder()."crashes"))
                mkdir(BlockHunt::getInstance()->getDataFolder()."crashes");
            file_put_contents($fileName,
                "You can report this error here: https://github.com/OguzhanUmutlu/BlockHunt/issues".
                "\nException timestamp: " . microtime(true).
                "\nException date: " . date(DATE_ATOM).
                "\nException message: " . $exception->getMessage().
                "\nException code: " . $exception->getCode().
                "\nException file: " . $exception->getFile().
                "\nException line: " . $exception->getLine().
                "\nException previous: " . $exception->getPrevious().
                "\nException trace: " . $exception->getTraceAsString());
            BlockHunt::getInstance()->getLogger()->warning($this->data->name . " arena crashed! You can report error in this file: ".$fileName);
            BlockHunt::getInstance()->getLogger()->warning("To report bugs: https://github.com/OguzhanUmutlu/BlockHunt/issues");
        }
    }

    /*** @return ArenaData */
    public function getData(): ArenaData {
        return $this->data;
    }

    /*** @return Player[] */
    public function getPlayers(): array {
        return array_merge($this->hunters, $this->seekers);
    }

    /*** @return Player[] */
    public function getHunters(): array {
        return $this->hunters;
    }

    /*** @return Player[] */
    public function getSeekers(): array {
        return $this->seekers;
    }

    /*** @return int */
    public function getId(): int {
        return $this->id;
    }

    public function getWorldPath(): string {
        return $this->getServer()->getDataPath()."worlds/".$this->data->map;
    }

    public function getWorldDataPath(): string {
        return BlockHunt::getInstance()->getDataFolder()."worlds/".$this->data->map.".zip";
    }

    public function createWorld(): void {
        $this->status = self::STATUS_ARENA_SETUP;
        if(!file_exists($this->getWorldDataPath())) {
            $this->stop(true);
            BlockHunt::getInstance()->getLogger()->warning($this->data->name . " arena crashed! Error: Saved zip not found.");
            BlockHunt::getInstance()->getLogger()->warning("To report bugs: https://github.com/OguzhanUmutlu/BlockHunt/issues");
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
        if(isset($this->getPlayers()[$player->getName()])) return;
        if(count($this->seekers) < count($this->hunters))
            $this->seekers[$player->getName()] = $player;
        else $this->hunters[$player->getName()] = $player;
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
        if(!isset($this->getPlayers()[$player->getName()])) return;
        $player->getInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->teleport($this->getServer()->getDefaultLevel()->getSpawnLocation());
        $b = $this->blocks[$player->getName()] ?? null;
        if($b instanceof Block)
            $this->getLevel()->setBlock($b, Block::get(0));
        else if($b instanceof BlockHuntEntity)
            $b->flagForDespawn();
        switch($this->getTeam($player)) {
            case self::TEAM_SEEKERS:
                unset($this->seekers[$player->getName()]);
                foreach((BlockHunt::getInstance()->hide[$player->getName()] ?? []) as $p)
                    if($p instanceof Player && !$p->isClosed()) {
                        $p->showPlayer($player);
                        unset(BlockHunt::getInstance()->hide[$player->getName()][$p->getName()]);
                    }
                break;
            case self::TEAM_HUNTERS:
                unset($this->hunters[$player->getName()]);
                break;
        }
        $this->broadcast(self::T("left-message", [$player->getName()]));
    }

    public function setDead(Player $player): void {
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $b = $this->blocks[$player->getName()] ?? null;
        if($b instanceof Block)
            $this->getLevel()->setBlock($b, Block::get(0));
        else if($b instanceof BlockHuntEntity)
            $b->flagForDespawn();
        switch($this->getTeam($player)) {
            case self::TEAM_SEEKERS:
                unset($this->seekers[$player->getName()]);
                $this->hunters[$player->getName()] = $player;
                foreach((BlockHunt::getInstance()->hide[$player->getName()] ?? []) as $p)
                    if($p instanceof Player && !$p->isClosed()) {
                        $p->showPlayer($player);
                        unset(BlockHunt::getInstance()->hide[$player->getName()][$p->getName()]);
                    }
                $player->teleport($this->getData()->spawn);
                $this->freeze[$player->getName()] = time()+15;
                $player->addEffect(new EffectInstance(Effect::getEffect(Effect::BLINDNESS), 300, 255, false));
                $this->broadcast(self::T("died-message-second", [$player->getName()]));
                break;
            case self::TEAM_HUNTERS:
                (new BlockHuntLoseEvent($player, $this))->call();
                $player->setGamemode(3);
                $bed = Item::get(Item::BED)->setCustomName(self::T("leave-item"));
                $bed->getNamedTag()->setString("blockHuntBed", "true");
                $player->getInventory()->setItem(8, $bed);
                $player->sendTitle("§r", self::T("died-title"), 0, 20, 0);
                $this->broadcast(self::T("died-message", [$player->getName()]));
                unset($this->hunters[$player->getName()]);
                break;
        }
    }

    public const TEAM_SEEKERS = "seekers";
    public const TEAM_HUNTERS = "hunters";

    public function getTeam(Player $player): ?string {
        if(!isset($this->getPlayers()[$player->getName()])) return null;
        if(isset($this->seekers[$player->getName()]))
            return self::TEAM_SEEKERS;
        return self::TEAM_HUNTERS;
    }

    public function broadcast(string $message): void {
        foreach($this->getPlayers() as $player)
            $player->sendMessage($message);
    }

    /*** @return int */
    public function getStatus(): int {
        return $this->status;
    }
}
