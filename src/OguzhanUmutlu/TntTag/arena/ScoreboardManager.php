<?php

namespace OguzhanUmutlu\TntTag\arena;

use OguzhanUmutlu\TntTag\scoreboard\ScoreboardAPI;
use OguzhanUmutlu\TntTag\TntTag;
use pocketmine\Player;

class ScoreboardManager {
    private const EMPTY_CACHE = ["§0\e", "§1\e", "§2\e", "§3\e", "§4\e", "§5\e", "§6\e", "§7\e", "§8\e", "§9\e", "§a\e", "§b\e", "§c\e", "§d\e", "§e\e"];
    private $arena;
    private $networkBound = [];
    private $lastState = [];

    public function __construct(Arena $arena) {
        $this->arena = $arena;
    }

    public function addPlayer(Player $pl): void {
        ScoreboardAPI::setScore($pl, TntTag::T("scoreboards.title"), ScoreboardAPI::SORT_ASCENDING);
        $this->updateScoreboard($pl);
    }

    private function updateScoreboard(Player $pl): void {
        if(!$pl->isOnline()) {
            $this->removePlayer($pl);
            return;
        }
        $keys = [
            "{name}",
            "{players}",
            "{required_players}",
            "{min_players}",
            "{max_players}",
            "{countdown}",
            "{tagged}"
        ];
        $values = [
            $this->arena->getData()->name,
            count($this->arena->getPlayers()),
            $this->arena->getData()->minPlayer-count($this->arena->getPlayers()),
            $this->arena->getData()->minPlayer,
            $this->arena->getData()->maxPlayer,
            $this->arena->getCountdown(),
            $this->arena->tagged ? $this->arena->tagged->getDisplayName() : "none"
        ];
        switch($this->arena->getStatus()) {
            case Arena::STATUS_ARENA_WAITING:
                $data = array_merge([" "], array_map(function($line) use ($values, $keys) {
                    return str_replace(
                        $keys,
                        $values,
                        TntTag::T("scoreboards.waiting.".$line)
                    );
                }, TntTag::getInstance()->messages["scoreboards"]["waiting"]));
                break;
            case Arena::STATUS_ARENA_STARTING:
                $data = array_merge([" "], array_map(function($line) use ($values, $keys) {
                    return str_replace(
                        $keys,
                        $values,
                        TntTag::T("scoreboards.starting.".$line)
                    );
                }, TntTag::getInstance()->messages["scoreboards"]["starting"]));
                break;
            case Arena::STATUS_ARENA_RUNNING:
                $data = array_merge([" "], array_map(function($line) use ($values, $keys) {
                    return str_replace(
                        $keys,
                        $values,
                        TntTag::T("scoreboards.running.".$line)
                    );
                }, TntTag::getInstance()->messages["scoreboards"]["running"]));
                break;
            case Arena::STATUS_ARENA_CLOSED:
                $data = array_merge([" "], array_map(function($line) use ($values, $keys) {
                    return str_replace(
                        $keys,
                        $values,
                        TntTag::T("scoreboards.closed.".$line)
                    );
                }, TntTag::getInstance()->messages["scoreboards"]["closed"]));
                break;
            default:
                $data = [" ", "An error occured!"];
                break;
        }
        foreach ($data as $scLine => $message) {
            ScoreboardAPI::setScoreLine($pl, $scLine, $message);
            $line = $scLine + 1;
            if (($this->networkBound[$pl->getName()][$line] ?? -1) === $message) {
                continue;
            }
            ScoreboardAPI::setScoreLine($pl, $line, $message);
            $this->networkBound[$pl->getName()][$line] = $message;
        }
    }

    public function tickScoreboard(): void {
        foreach ($this->arena->getPlayers() as $player)
            $this->updateScoreboard($player);
    }

    public function resetScoreboard(): void {
        foreach ($this->arena->getPlayers() as $player) $this->removePlayer($player);
        $this->networkBound = [];
    }

    public function removePlayer(Player $pl): void {
        unset($this->networkBound[$pl->getName()]);
        ScoreboardAPI::removeScore($pl);
    }
}