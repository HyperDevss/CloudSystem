<?php

namespace Cloud\network;

use Cloud\api\NotifyAPI;
use Cloud\Cloud;
use Cloud\lib\snooze\SleeperNotifier;
use Cloud\network\protocol\packet\ConnectionPacket;
use Cloud\network\protocol\packet\DispatchCommandPacket;
use Cloud\network\protocol\packet\InvalidPacket;
use Cloud\network\protocol\packet\ListServersRequestPacket;
use Cloud\network\protocol\packet\ListServersResponsePacket;
use Cloud\network\protocol\packet\LoginRequestPacket;
use Cloud\network\protocol\packet\LoginResponsePacket;
use Cloud\network\protocol\packet\NotifyStatusUpdatePacket;
use Cloud\network\protocol\packet\Packet;
use Cloud\network\protocol\packet\PlayerJoinPacket;
use Cloud\network\protocol\packet\PlayerKickPacket;
use Cloud\network\protocol\packet\PlayerQuitPacket;
use Cloud\network\protocol\packet\ProxyPlayerJoinPacket;
use Cloud\network\protocol\packet\ProxyPlayerQuitPacket;
use Cloud\network\protocol\packet\SaveServerPacket;
use Cloud\network\protocol\packet\StartServerRequestPacket;
use Cloud\network\protocol\packet\StartServerResponsePacket;
use Cloud\network\protocol\packet\StopServerRequestPacket;
use Cloud\network\protocol\packet\StopServerResponsePacket;
use Cloud\network\protocol\packet\TestPacket;
use Cloud\network\protocol\packet\TextPacket;
use Cloud\network\protocol\PacketPool;
use Cloud\network\udp\UDPClient;
use Cloud\network\udp\UDPServer;
use Cloud\network\utils\Address;
use Cloud\player\CloudPlayer;
use Cloud\player\PlayerManager;
use Cloud\scheduler\ClosureTask;
use Cloud\server\Server;
use Cloud\server\ServerManager;
use Cloud\server\status\ServerStatus;
use Cloud\template\Template;
use Cloud\template\TemplateManager;
use Cloud\thread\Thread;
use Cloud\utils\CloudLogger;
use CloudBridge\network\protocol\packet\LogPacket;

class CloudSocket {

    private static self $instance;
    private UDPServer $udpServer;
    private PacketPool $packetPool;
    /** @var UDPClient[] */
    private array $clients = [];

    public function __construct(Address $bindAddress) {
        self::$instance = $this;
        $this->udpServer = new UDPServer();
        $this->packetPool = new PacketPool();

        CloudLogger::getInstance()->info("Binding to §e" . $bindAddress . "§r...");
        $this->udpServer->bind($bindAddress);
        CloudLogger::getInstance()->info("Successfully binded to §e" . $bindAddress . "§r!");

        Cloud::getInstance()->getTaskScheduler()->scheduleTask(new ClosureTask(function (): void {
            $this->onRun();
        }, 0, true, 1));
    }

    public function getUdpServer(): UDPServer {
        return $this->udpServer;
    }

    public function onRun() {
        if ($this->udpServer->isConnected()) {
            if (($this->udpServer->read($buffer, $address, $port)) !== false) {
                if ($this->isLocalHost($address)) {
                    $packet = $this->packetPool->getPacket($buffer);
                    if (!$packet instanceof InvalidPacket) {
                        $packet->decode();
                        $client = new UDPClient(new Address($address, $port));

                        if (!$this->isVerified($client)) {
                            if ($packet instanceof LoginRequestPacket) {
                                if (($checkServer = ServerManager::getInstance()->getServer($packet->server)) !== null) {
                                    $this->verify($client, $checkServer->getName());
                                    CloudLogger::getInstance()->info("The server §e" . $checkServer->getName() . " §rwas §averified§r!");
                                    $this->sendPacket(LoginResponsePacket::create(LoginResponsePacket::SUCCESS), $client);
                                } else {
                                    CloudLogger::getInstance()->warning("Received a login request from a not existing server! §8(§e" . $packet->server . "§8)");
                                    $this->sendPacket(LoginResponsePacket::create(LoginResponsePacket::DENIED), $client);
                                }
                            }
                        } else {
                            if ($packet instanceof ConnectionPacket) {
                                if (($server = ServerManager::getInstance()->getServer($packet->server)) !== null) {
                                    $server->setGotConnectionResponse(true);
                                }
                            } else if ($packet instanceof DispatchCommandPacket) {
                                if (($server = ServerManager::getInstance()->getServer($packet->server)) !== null) {
                                    ServerManager::getInstance()->dispatchCommand($server, $packet->commandLine);
                                }
                            } else if ($packet instanceof SaveServerPacket) {
                                if (($server = ServerManager::getInstance()->getServer($packet->server)) !== null) {
                                    ServerManager::getInstance()->saveServer($server);
                                }
                            } else if ($packet instanceof NotifyStatusUpdatePacket) {
                                NotifyAPI::getInstance()->setNotify($packet->player, $packet->v);
                            } else if ($packet instanceof PlayerJoinPacket) {
                                if (($player = PlayerManager::getInstance()->getPlayer($packet->name)) !== null) {
                                    if ($player->getCurrentServer() == "") $player->setCurrentServer($packet->currentServer);
                                    PlayerManager::getInstance()->addServerPlayer($player);
                                } else {
                                    $player = new CloudPlayer($packet->name, new Address($packet->address, $packet->port), $packet->uuid, $packet->xuid, $packet->currentServer, (PlayerManager::getInstance()->hasLastProxy($packet->name) ? PlayerManager::getInstance()->getLastProxy($packet->name) : ""));
                                    PlayerManager::getInstance()->handleLogin($player);
                                    PlayerManager::getInstance()->addServerPlayer($player);
                                    if ($player->getCurrentProxy() !== "") PlayerManager::getInstance()->addProxyPlayer($player);
                                }
                            } else if ($packet instanceof PlayerQuitPacket) {
                                if (($player = PlayerManager::getInstance()->getPlayer($packet->name)) !== null) {
                                    PlayerManager::getInstance()->handleLogout($player);
                                    PlayerManager::getInstance()->removeServerPlayer($player);
                                    $player->setCurrentServer("");
                                }
                            } else if ($packet instanceof ProxyPlayerJoinPacket) {
                                $player = new CloudPlayer($packet->name, new Address($packet->address, $packet->port), $packet->uuid, $packet->xuid, "", $packet->currentProxy);
                                PlayerManager::getInstance()->handleLogin($player);
                                PlayerManager::getInstance()->addProxyPlayer($player);
                            } else if ($packet instanceof ProxyPlayerQuitPacket) {
                                if (($player = PlayerManager::getInstance()->getPlayer($packet->name)) !== null) {
                                    PlayerManager::getInstance()->handleLogout($player);
                                    PlayerManager::getInstance()->removeLastProxy($player);
                                    PlayerManager::getInstance()->removeProxyPlayer($player);
                                    $player->setCurrentProxy("");
                                }
                            } else if ($packet instanceof LogPacket) {
                                $serverName = $this->getServer($client);
                                if ($serverName !== null) CloudLogger::getInstance()->message("§e" . $serverName . ": §r" . $packet->message);
                            } else if ($packet instanceof TextPacket) {
                                $this->broadcastPacket($packet);
                            } else if ($packet instanceof PlayerKickPacket) {
                                $this->broadcastPacket($packet);
                            } else if ($packet instanceof StartServerRequestPacket) {
                                if (($template = TemplateManager::getInstance()->getTemplate($packet->template)) !== null) {
                                    if (count(ServerManager::getInstance()->getServersOfTemplate($template)) >= $template->getMaxServers()) {
                                        $message = "§cNo servers from the template §e" . $template->getName() . " §ccan be started anymore because the limit was reached!";
                                        $this->sendPacket(StartServerResponsePacket::create($packet->player, $message, StartServerResponsePacket::ERROR), $client);
                                    } else {
                                        $this->sendPacket(StartServerResponsePacket::create($packet->player, "", StartServerResponsePacket::SUCCESS), $client);
                                        ServerManager::getInstance()->startServer($template, $packet->count);
                                    }
                                } else {
                                    $message = "§cThe template §e" . $packet->template . " §cdoesn't exists!";
                                    $this->sendPacket(StartServerResponsePacket::create($packet->player, $message, StartServerResponsePacket::ERROR), $client);
                                }
                            } else if ($packet instanceof StopServerRequestPacket) {
                                if ($packet->server == "all" || $packet->server == "*") {
                                    $this->sendPacket(StopServerResponsePacket::create($packet->player, "", StopServerResponsePacket::SUCCESS), $client);
                                } else {
                                    if (($template = TemplateManager::getInstance()->getTemplate($packet->server)) !== null) {
                                        $this->sendPacket(StopServerResponsePacket::create($packet->player, "", StopServerResponsePacket::SUCCESS), $client);
                                        ServerManager::getInstance()->stopTemplate($template);
                                    } else if (($server = ServerManager::getInstance()->getServer($packet->server)) !== null) {
                                        $this->sendPacket(StopServerResponsePacket::create($packet->player, "", StopServerResponsePacket::SUCCESS), $client);
                                        ServerManager::getInstance()->stopServer($server);
                                    } else {
                                        $message = "§cThe server §e" . $packet->server . " §cdoesn't exists!";
                                        $this->sendPacket(StopServerResponsePacket::create($packet->player, $message, StopServerResponsePacket::ERROR), $client);
                                    }
                                }
                            } else if ($packet instanceof ListServersRequestPacket) {
                                $servers = [];
                                foreach (ServerManager::getInstance()->getServers() as $server) $servers[$server->getName()] = ["Port" => $server->getPort(), "Players" => $server->getPlayersCount(), "MaxPlayers" => $server->getTemplate()->getMaxPlayers(), "Template" => ($server->getTemplate()->getType() == Template::TYPE_SERVER ? "§e" . $server->getTemplate()->getName() : "§c" . $server->getTemplate()->getName()), "ServerStatus" => $this->statusString($server->getServerStatus())];
                                $this->sendPacket(ListServersResponsePacket::create($packet->player, $servers), $client);
                            }
                        }
                    }
                } else {
                    CloudLogger::getInstance()->warning("Received a packet from a external client!");
                }
            }
        }
    }

    private function statusString(int $status): string {
        if ($status == ServerStatus::STATUS_STARTING) return "§2STARTING";
        else if ($status == ServerStatus::STATUS_STARTED) return "§aSTARTED";
        else if ($status == ServerStatus::STATUS_STOPPING) return "§4STOPPING";
        else if ($status == ServerStatus::STATUS_STOPPED) return "§cSTOPPED";
        return "";
    }

    public function isVerified(UDPClient $client): bool {
        foreach ($this->clients as $server => $serverClient) {
            if ($client->getAddress()->equals($serverClient->getAddress())) return true;
        }
        return false;
    }

    public function isVerifiedServer(Server $server): bool {
        foreach ($this->clients as $serverName => $serverClient) {
            if ($serverName == $server->getName()) return true;
        }
        return false;
    }

    public function verify(UDPClient $client, string $server) {
        if (!isset($this->clients[$server])) $this->clients[$server] = $client;
    }

    public function unverify(string $server) {
        if (isset($this->clients[$server])) unset($this->clients[$server]);
    }

    private function isLocalHost(string $address): bool {
        return $address == "127.0.0.1" || $address == "0.0.0.0" || $address == "8.8.8.8" || $address == "localhost";
    }

    public function getClient(string $serverName): ?UDPClient {
        foreach ($this->clients as $server => $client) {
            if ($server == $serverName) return $client;
        }
        return null;
    }

    public function getServer(UDPClient $client): ?string {
        foreach ($this->clients as $server => $serverClient) {
            if ($client->getAddress()->equals($serverClient->getAddress())) return $server;
        }
        return null;
    }

    public function sendPacket(Packet $packet, UDPClient $client) {
        $client->sendPacket($packet);
    }

    public function broadcastPacket(Packet $packet) {
        foreach ($this->clients as $client) $client->sendPacket($packet);
    }

    public function getPacketPool(): PacketPool {
        return $this->packetPool;
    }

    /** @return UDPClient[] */
    public function getClients(): array {
        return $this->clients;
    }

    public static function getInstance(): CloudSocket {
        return self::$instance;
    }
}