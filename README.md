# TntTag

# ComplexCrates
[![](https://poggit.pmmp.io/shield.state/TntTag)](https://poggit.pmmp.io/p/TntTag)
[![](https://poggit.pmmp.io/shield.api/TntTag)](https://poggit.pmmp.io/p/TntTag)
[![](https://poggit.pmmp.io/shield.dl.total/TntTag)](https://poggit.pmmp.io/p/TntTag)
[![](https://poggit.pmmp.io/shield.dl/TntTag)](https://poggit.pmmp.io/p/TntTag)

TntTag minigame for PocketMine-MP!

# What is this minigame?

In this minigame players trying to run from tagged players.

Tagged players can tag others and they explode after a couple seconds.

Last one alive wins.

# How to setup?

Just simply use `/tnttagadmin setup` command and start setup session!

# API

Use plugin

```php
use OguzhanUmutlu\TntTag\TntTag;
```

Get player's arena:

```php
TntTag::getInstance()->arenaManager->getPlayerArena($player);
```

Events:

```php
use OguzhanUmutlu\TntTag\events\TntTagWinEvent;
use OguzhanUmutlu\TntTag\events\TntTagLoseEvent;
```

```php
/*** TntTagWinEvent|TntTagLoseEvent */
$player = $event->getPlayer();
$arena = $event->getArena();
```


# TODO
- idk

# Reporting bugs
**You may open an issue on the TntTag GitHub repository for report bugs**
https://github.com/OguzhanUmutlu/TntTag/issues
