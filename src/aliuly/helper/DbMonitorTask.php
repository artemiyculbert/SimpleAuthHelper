<?php
namespace aliuly\helper;

use pocketmine\scheduler\Task;
use pocketmine\event\Listener;
use aliuly\helper\Main as HelperPlugin;
use aliuly\helper\common\mc;
use aliuly\helper\common\PluginCallbackTask;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerJoinEvent;

class DbMonitorTask extends Task implements Listener{
  protected $canary;
  protected $ok;
  protected $dbm;
  protected $fix;

  static public function defaults() {
    //= cfg:monitor-settings
		return [
      "# canary-account" => "account to query",//this account is tested to check database proper operations
      "canary-account" => "test user",
      "# check-interval" => "how to often to check database (seconds)",
      "check-interval" => 600,
		];
	}
	public function __construct(HelperPlugin $owner,$cfg){
  	$this->plugin = $owner;
    $this->canary = $cfg["canary-account"];
    if ($owner->auth->isEnabled()) {
      $this->dbm = $owner->auth->getDataProvider();
      $this->ok = true; // Assume things are OK...
      if (!$this->pollDB()) {
        // If this fails then canary account doesn't exist yet... create it
        $player = $this->plugin->getServer()->getOfflinePlayer($this->canary);
        if ($player === null) {
          throw new \RuntimeException("canary account definition error!");
          return;
        }
        $err = $this->dbm->registerPlayer($player,"N/A");
        if ($err === null) {
          throw new \RuntimeException("Unable to register canary account!");
        }
      }
    } else {
      $this->ok = false;
    }

    $owner->getScheduler()->scheduleRepeatingTask($this,$cfg["check-interval"]*20);
    $owner->getServer()->getPluginManager()->registerEvents($this, $owner);
	}
  private function setStatus($mode) {
    if ($this->ok === $mode) return;
    $this->ok = $mode;
    if ($mode) {
      $this->plugin->getLogger()->info(mc::_("Restored database connection"));
      Server::getInstance()->broadcastMessage(TextFormat::GREEN.mc::_("Database connectivity restored!"));
      return;
    }
    Server::getInstance()->getLogger()->error(mc::_("LOST DATABASE CONNECTION!"));
    Server::getInstance()->broadcastMessage(TextFormat::RED.mc::_("Detected loss of database connectivity!"));
    // Kick all unregistered players...
    $auth = Server::getInstance()->getPluginManager()->getPlugin("SimpleAuth");
    if ($auth !== null) {
      $cnt = 0;
      foreach (Server::getInstance()->getOnlinePlayers() as $ll) {
        if (!$auth->isPlayerAuthenticated($ll)) {
          $this->delayedKick($ll,mc::_("Database is experiencing technical difficulties"));
          ++$cnt;
        }
      }
      if ($cnt)
		  Server::getInstance()->broadcastMessage(
          TextFormat::BLUE.
          mc::n(
            mc::_("one unauthenticated player was kicked"),
            mc::_("%1% unauthenticated players were kicked", $cnt),
            $cnt
          )
        );
      return;
    }
  }
  private function enableAuth($mgr,$auth) {
    if ($auth === null) return false; // OK, this is weird!
    if ($auth->isEnabled()) return true;
    Server::getInstance()->getLogger()->info(mc::_("Enabling SimpleAuth"));
    $mgr->enablePlugin($auth);
    if (!$auth->isEnabled()) return false;
    $this->dbm = $auth->getDataProvider();
    return true;
  }
  private function pollDB() {
    $player = Server::getInstance()->getOfflinePlayer($this->canary);
    if ($player == null) return true;//Automatically assume things are OK :)
    try {
      return $this->dbm->isPlayerRegistered($player);
    } catch (\Exception $e) {
		Server::getInstance()->getLogger()->error(mc::_("DBM Error: %1%",$e->getMessage()));
    }
    return false;
  }

	public function onRun(int $currentTicks){
    $mgr = Server::getInstance()->getPluginManager();
    $auth = $mgr->getPlugin("SimpleAuth");
    if ($auth === null) return; // OK, this is weird!

    if (!$auth->isEnabled()) {
      if (!$this->enableAuth($mgr,$auth)) return; // Ouch...
    }
    if ($this->pollDB()) {
      $this->setStatus(true);
      return;
    }
    /*
     * Lost connection to database...
     */
    $this->setStatus(false);
    /*
     * let's try to reconnect by resetting SimpleAuth
     */
    if ($auth->isEnabled()) {
		Server::getInstance()->getLogger()->info(mc::_("Disabling SimpleAuth"));
      $mgr->disablePlugin($auth);
    }
    if (!$auth->isEnabled()) {
		Server::getInstance()->getLogger()->info(mc::_("Enabling SimpleAuth"));
      if (!$this->enableAuth($mgr,$auth)) return; // Ouch...
    }
    if ($this->pollDB()) $this->setStatus(true);
	}
  public function doKick($n,$msg) {
    $pl = Server::getInstance()->getPlayer($n);
    if ($pl === null) return;
    $pl->kick($msg);
  }
  private function delayedKick($pl,$msg) {
    $this->plugin->getScheduler()->scheduleDelayedTask(
      new PluginCallbackTask($this->plugin,[$this,"doKick"],[$pl->getName(),$msg]),
      10
    );
  }
  public function onConnect(PlayerLoginEvent $ev) {
    $this->onRun(0);
  }
  public function onJoin(PlayerJoinEvent $ev) {
    if ($this->ok) return;
    $this->delayedKick($ev->getPlayer(),mc::_("Database is experiencing technical difficulties"));
  }
}
