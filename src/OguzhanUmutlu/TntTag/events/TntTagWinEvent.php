<?php

namespace OguzhanUmutlu\TntTag\events;

use OguzhanUmutlu\TntTag\arena\Arena;
use pocketmine\event\player\PlayerEvent;
use pocketmine\Player;

class TntTagWinEvent extends PlayerEvent {
    /*** @var Arena */
    private $arena;
    public function __construct(Player $player, Arena $arena) {
        $this->player = $player;
        $this->arena = $arena;
    }

    /*** @return Arena */
    public function getArena(): Arena {
        return $this->arena;
    }
}