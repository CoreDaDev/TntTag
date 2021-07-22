<?php

namespace OguzhanUmutlu\TntTag\scoreboard;

use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\Player;

class ScoreboardAPI {
    private const objectiveName = "objective";
    private const criteriaName = "dummy";
    private const MIN_LINES = 1;
    private const MAX_LINES = 15;
    public const SORT_ASCENDING = 0;
    public const SORT_DESCENDING = 1;
    public const SLOT_LIST = "list";
    public const SLOT_SIDEBAR = "sidebar";
    public const SLOT_BELOW_NAME = "belowname";
    private static $scoreboards = [];
    public static function setScore(Player $player, string $displayName, int $slotOrder = self::SORT_ASCENDING, string $displaySlot = self::SLOT_SIDEBAR): void{
        if(isset(self::$scoreboards[$player->getName()]))
            self::removeScore($player);
        $pk = new SetDisplayObjectivePacket();
        $pk->displaySlot = $displaySlot;
        $pk->objectiveName = self::objectiveName;
        $pk->displayName = $displayName;
        $pk->criteriaName = self::criteriaName;
        $pk->sortOrder = $slotOrder;
        $player->sendDataPacket($pk);
        self::$scoreboards[$player->getName()] = self::objectiveName;
    }

    public static function removeScore(Player $player): void{
        $objectiveName = self::objectiveName;
        $pk = new RemoveObjectivePacket();
        $pk->objectiveName = $objectiveName;
        $player->sendDataPacket($pk);
        if(isset(self::$scoreboards[($player->getName())]))
            unset(self::$scoreboards[$player->getName()]);
    }

    public static function getScoreboards(): array{
        return self::$scoreboards;
    }

    public static function hasScore(Player $player): bool{
        return isset(self::$scoreboards[$player->getName()]);
    }

    public static function setScoreLine(Player $player, int $line, ?string $message): void{
        if(!isset(self::$scoreboards[$player->getName()]))
            return;
        if($line < self::MIN_LINES || $line > self::MAX_LINES)
            return;
        $entry = new ScorePacketEntry();
        $entry->objectiveName = self::objectiveName;
        $entry->type = $entry::TYPE_FAKE_PLAYER;
        $entry->customName = $message ?? "";
        $entry->score = $line;
        $entry->scoreboardId = $line;
        $pk = new SetScorePacket();
        $pk->type = SetScorePacket::TYPE_REMOVE;
        $pk->entries[] = $entry;
        $player->sendDataPacket($pk);
        if($message !== null) {
            $pk = new SetScorePacket();
            $pk->type = SetScorePacket::TYPE_CHANGE;
            $pk->entries[] = $entry;
            $player->sendDataPacket($pk);
        }
    }
}