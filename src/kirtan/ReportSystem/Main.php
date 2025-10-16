<?php

namespace kirtan\ReportSystem;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use pocketmine\event\Listener;
use SQLite3;
use Exception;

class Main extends PluginBase implements Listener {

    private SQLite3 $db;

    public function onEnable(): void {
        @mkdir($this->getDataFolder());
        $this->saveResource("config.yml");

        try {
            $this->db = new SQLite3($this->getDataFolder() . "reports.db");
            $this->db->exec("CREATE TABLE IF NOT EXISTS reports (
                id TEXT PRIMARY KEY,
                reporter TEXT,
                target TEXT,
                reason TEXT,
                time INTEGER,
                status TEXT,
                closed_by TEXT,
                closed_time INTEGER
            )");
        } catch (Exception $e) {
            $this->getLogger()->error("SQLite initialization failed: " . $e->getMessage());
            return;
        }

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info(TF::GREEN . "AdvancedReportSystem (SQLite) by kirtan enabled (API 5.0.0)");
    }

    public function onDisable(): void {
        if(isset($this->db)){
            $this->db->close();
        }
        $this->getLogger()->info(TF::YELLOW . "AdvancedReportSystem disabled");
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        switch(strtolower($command->getName())){

            // REPORT COMMAND
            case "report":
                if(!$sender instanceof Player){
                    $sender->sendMessage(TF::RED . "Only players can use this command!");
                    return true;
                }
                if(count($args) < 2){
                    $sender->sendMessage(TF::YELLOW . "Usage: /report <player> <reason>");
                    return true;
                }

                $target = array_shift($args);
                $reason = trim(implode(" ", $args));

                $id = (string)(time() . "-" . mt_rand(1000, 9999));
                $stmt = $this->db->prepare("INSERT INTO reports (id, reporter, target, reason, time, status) VALUES (:id, :reporter, :target, :reason, :time, :status)");
                $stmt->bindValue(":id", $id);
                $stmt->bindValue(":reporter", $sender->getName());
                $stmt->bindValue(":target", $target);
                $stmt->bindValue(":reason", $reason);
                $stmt->bindValue(":time", time());
                $stmt->bindValue(":status", "open");
                $stmt->execute();

                $sender->sendMessage(TF::GREEN . "✅ Report submitted successfully! ID: $id");

                foreach($this->getServer()->getOnlinePlayers() as $p){
                    if($p->hasPermission("reports.receive") || $p->isOp()){
                        $p->sendMessage(TF::AQUA . "[Report] {$sender->getName()} → {$target}: {$reason} (ID: {$id})");
                    }
                }

                $this->getLogger()->info("[Report] $id - {$sender->getName()} -> {$target}: {$reason}");
                return true;

            // REPORTS LIST
            case "reports":
                if(!$sender->hasPermission("reports.view") && !$sender->isOp()){
                    $sender->sendMessage(TF::RED . "You don't have permission to view reports.");
                    return true;
                }

                $result = $this->db->query("SELECT * FROM reports ORDER BY time DESC");
                $found = false;
                while($row = $result->fetchArray(SQLITE3_ASSOC)){
                    $found = true;
                    $time = date("Y-m-d H:i:s", $row["time"]);
                    $sender->sendMessage(TF::GRAY . "[{$row["status"]}] ID: {$row["id"]} {$row["reporter"]} → {$row["target"]} | {$row["reason"]} | {$time}");
                }

                if(!$found){
                    $sender->sendMessage(TF::YELLOW . "No reports found.");
                }
                return true;

            // CLOSE REPORT
            case "reportclose":
                if(!$sender->hasPermission("reports.close") && !$sender->isOp()){
                    $sender->sendMessage(TF::RED . "You don't have permission to close reports.");
                    return true;
                }

                if(count($args) < 1){
                    $sender->sendMessage(TF::YELLOW . "Usage: /reportclose <id>");
                    return true;
                }

                $id = $args[0];
                $check = $this->db->prepare("SELECT * FROM reports WHERE id = :id");
                $check->bindValue(":id", $id);
                $res = $check->execute()->fetchArray(SQLITE3_ASSOC);

                if(!$res){
                    $sender->sendMessage(TF::RED . "Report not found: $id");
                    return true;
                }

                $stmt = $this->db->prepare("UPDATE reports SET status = 'closed', closed_by = :closed_by, closed_time = :time WHERE id = :id");
                $stmt->bindValue(":closed_by", $sender->getName());
                $stmt->bindValue(":time", time());
                $stmt->bindValue(":id", $id);
                $stmt->execute();

                $sender->sendMessage(TF::GREEN . "✅ Report $id closed successfully.");
                $this->getLogger()->info("Report closed: $id by {$sender->getName()}");
                return true;
        }
        return false;
    }
}