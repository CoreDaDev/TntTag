<?php

namespace OguzhanUmutlu\TntTag\arena;

use pocketmine\math\Vector3;

class ArenaData {
    public $minPlayer = 4;
    public $maxPlayer = 16;
    /*** @var Vector3 */
    public $spawn;
    public $startingCountdown = 10;
    public $map = "";
    public $name = "";
}