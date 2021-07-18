<?php

namespace OguzhanUmutlu\TntTag\arena;

use OguzhanUmutlu\TntTag\TntTag;
use pocketmine\Player;
use pocketmine\scheduler\Task;

class TagTask extends Task {
    public $t;
    public $ar;
    public function __construct(Player $player, Arena $arena) {
        $this->t = $player;
        $this->ar = $arena;
    }
    public function onRun(int $currentTick) {
        $p = $this->t;
        if($p->isClosed() || !$p->isOnline() || !$this->ar->tagged || $this->ar->tagged->getName() != $p->getName()) {
            if($this->getHandler())
                $this->getHandler()->cancel();
            return;
        }
        $p->sendActionBarMessage(TntTag::T("tag-popup"));
    }
}