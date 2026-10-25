<?php
namespace Phalcon\Filter\Validation { interface ValidatorInterface {} class AbstractValidator {} }
namespace Phalcon\Filter { class Validation { public function add($f, $v) {} public function validate($d) { return []; } } }
namespace OPNsense\Core {
    class Backend {
        public static $calls = [];
        public function configdRun($event, $detach = false, $timeout = 120, $ttl = 0) {
            self::$calls[] = $event;
            if (strpos($event, 'interface gateways list') === 0) {
                return json_encode(['WAN_GW' => 'WAN_GW - 203.0.113.1', 'WG_GW' => 'WG_GW - 10.64.0.1', 'WG_GW6' => 'WG_GW6 - fc00:bbbb::1']);
            }
            return '';
        }
        public function configdpRun($event, $params = [], $detach = false, $timeout = 120, $ttl = 0) {
            return $this->configdRun($event . ' ' . implode(' ', $params));
        }
    }
    class Syslog { public function __construct(...$a) {} public function __call($n, $a) { fwrite(STDERR, "syslog $n: " . json_encode($a) . "\n"); } }
}
namespace OPNsense\Routing {
    class Gateways {
        public function gatewaysIndexedByName(...$a) {
            return [
                'WAN_GW' => ['name' => 'WAN_GW', 'interface' => 'wan', 'ipprotocol' => 'inet'],
                'WG_GW' => ['name' => 'WG_GW', 'interface' => 'opt1', 'ipprotocol' => 'inet'],
                'WG_GW6' => ['name' => 'WG_GW6', 'interface' => 'opt1', 'ipprotocol' => 'inet6'],
            ];
        }
    }
}
namespace {
    if (!function_exists("gettext")) { function gettext($s) { return $s; } }
    $work = getenv('GWSW_WORK');
    require_once $work . '/app/config/AppConfig.php';
    $config = new OPNsense\Core\AppConfig([
        'application' => [
            'controllersDir' => $work . '/app/controllers/',
            'modelsDir' => $work . '/app/models/',
            'libraryDir' => $work . '/app/library/',
            'configDefault' => '/does/not/exist/config.xml',
            'configDir' => $work . '/conf',
            'cacheDir' => $work . '/tmp',
            'tempDir' => $work . '/tmp',
        ],
        'globals' => ['debug' => false, 'owner' => 'root:root', 'simulate_mode' => false],
    ]);
    include $work . '/app/config/loader.php';
}
