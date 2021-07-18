<?php

namespace OguzhanUmutlu\TntTag\manager;

use OguzhanUmutlu\TntTag\arena\Arena;
use OguzhanUmutlu\TntTag\TntTag;
use pocketmine\Player;
use pocketmine\Server;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

class ArenaManager {
    /*** @var Arena[] */
    private $arenas = [];
    public function createArena(Arena $arena, bool $save = false): bool {
        if(isset($this->arenas[$arena->getId()])) return false;
        $this->arenas[$arena->getId()] = $arena;
        if($save) {
            $array = [
                "minPlayer" => $arena->getData()->minPlayer,
                "maxPlayer" => $arena->getData()->maxPlayer,
                "spawn" => [
                    "x" => $arena->getData()->spawn->x,
                    "y" => $arena->getData()->spawn->y,
                    "z" => $arena->getData()->spawn->z
                ],
                "startingCountdown" => $arena->getData()->startingCountdown,
                "map" => $arena->getData()->map,
                "name" => $arena->getData()->name,
            ];
            TntTag::getInstance()->arenaConfig->setNested($arena->getData()->name, $array);
            TntTag::getInstance()->arenaConfig->save();
            TntTag::getInstance()->arenaConfig->reload();
            $level = Server::getInstance()->getLevelByName($arena->getData()->map);
            $level->save(true);
            Server::getInstance()->unloadLevel($level);
            $levelPath = Server::getInstance()->getDataPath() . "worlds" . DIRECTORY_SEPARATOR . $level->getFolderName();
            $zip = new ZipArchive();
            if(file_exists($arena->getWorldDataPath()))
                unlink($arena->getWorldDataPath());
            $zip->open($arena->getWorldDataPath(), ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(realpath($levelPath)), RecursiveIteratorIterator::LEAVES_ONLY);
            /** @var SplFileInfo $file */
            foreach ($files as $file)
                if(!$file->isDir()) {
                    $localPath = substr($file->getPath()."/".$file->getBasename(), strlen(Server::getInstance()->getDataPath()."worlds"));
                    $zip->addFile($file->getPath()."/".$file->getBasename(), $localPath);
                }
            $zip->close();
        }
        $arena->initArena();
        return true;
    }

    public function getArenaById(int $id): ?Arena {
        return $this->arenas[$id] ?? null;
    }

    /*** @return Arena[] */
    public function getArenas(): array {
        return $this->arenas;
    }

    public function getPlayerArena(Player $player): ?Arena {
        $res = null;
        foreach($this->arenas as $arena)
            if(isset($arena->getPlayers()[$player->getName()]))
                $res = $arena;
        return $res;
    }

    public function getAvailableArena(): ?Arena {
        $res = null;
        foreach($this->arenas as $arena)
            if($arena->getStatus() < Arena::STATUS_ARENA_RUNNING && count($arena->getPlayers()) < $arena->getData()->maxPlayer && !$arena->isPrivate())
                $res = $arena;
        return $res;
    }
}