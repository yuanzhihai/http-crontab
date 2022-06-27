<?php

namespace Fairy;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use think\Container;
use think\facade\Console;
use think\facade\Db;
use Workerman\Connection\TcpConnection;
use Workerman\Crontab\Crontab;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

/**
 * 注意：定时器开始、暂停、重起 都是在下一分钟开始执行
 * Class CrontabService
 * @package Fairy
 */
class HttpCrontab
{
    const FORBIDDEN_STATUS = '0';

    const NORMAL_STATUS = '1';

    //请求接口地址
    const INDEX_PATH = '/crontab/index';
    const ADD_PATH = '/crontab/add';
    const MODIFY_PATH = '/crontab/modify';
    const RELOAD_PATH = '/crontab/reload';
    const DELETE_PATH = '/crontab/delete';
    const FLOW_PATH = '/crontab/flow';
    const POOL_PATH = '/crontab/pool';
    const PING_PATH = '/crontab/ping';

    // 命令任务
    public const COMMAND_CRONTAB = '1';
    // 类任务
    public const CLASS_CRONTAB = '2';
    // URL任务
    public const URL_CRONTAB = '3';
    //shell
    public const SHELL_CRONTAB = '4';

    /**
     * worker 实例
     * @var Worker
     */
    private $worker;


    /**
     * 进程名
     * @var string
     */
    private $workerName = "Workerman Visible Crontab";


    /**
     * 任务进程池
     * @var Crontab[] array
     */
    private $crontabPool = [];

    /**
     * 调试模式
     * @var bool
     */
    private $debug = false;

    /**
     * 错误信息
     * @var
     */
    private $errorMsg = [];

    /**
     * 定时任务表
     * @var string
     */
    private $systemCrontabTable = 'system_crontab';

    /**
     * 定时任务日志表
     * @var string
     */
    private $systemCrontabLogTable = 'system_crontab_log';

    /**
     * 定时任务日志表后缀 按月分表
     * @var string|null
     */
    private $systemCrontabLogTableSuffix;

    /**
     * 最低PHP版本
     * @var string
     */
    private $lessPhpVersion = '7.2.5';

    /**
     * @var Dispatcher
     */
    private $dispatcher;

    /**
     * 安全秘钥
     * @var string
     */
    private $safeKey;

    /**
     * @param string $socketName 不填写表示不监听任何端口,格式为 <协议>://<监听地址> 协议支持 tcp、udp、unix、http、websocket、text
     * @param array $contextOption socket 上下文选项 http://php.net/manual/zh/context.socket.php
     */
    public function __construct(string $socketName = '', array $contextOption = [])
    {
        $this->checkEnv();
        $this->registerRoute();
        $this->initWorker($socketName, $contextOption);
    }

    /**
     * 初始化 worker
     * @param string $socketName
     * @param array $contextOption
     */
    private function initWorker(string $socketName = '', array $contextOption = [])
    {
        $socketName         = $socketName ?: 'http://127.0.0.1:2345';
        $this->worker       = new Worker($socketName, $contextOption);
        $this->worker->name = $this->workerName;
        if (isset($contextOption['ssl'])) {
            $this->worker->transport = 'ssl';//设置当前Worker实例所使用的传输层协议，目前只支持3种(tcp、udp、ssl)。默认为tcp。
        }
        $this->registerCallback();
    }

    /**
     * 注册路由
     */
    private function registerRoute()
    {
        $this->dispatcher = \FastRoute\simpleDispatcher(function (RouteCollector $r) {
            $r->get(self::INDEX_PATH, [$this, 'crontabIndex']);
            $r->post(self::ADD_PATH, [$this, 'crontabCreate']);
            $r->post(self::MODIFY_PATH, [$this, 'crontabModify']);
            $r->post(self::DELETE_PATH, [$this, 'crontabDelete']);
            $r->post(self::RELOAD_PATH, [$this, 'crontabReload']);
            $r->get(self::FLOW_PATH, [$this, 'crontabFlow']);
            $r->get(self::POOL_PATH, [$this, 'crontabPool']);
            $r->get(self::PING_PATH, [$this, 'crontabPong']);
        });
    }

    /**
     * 启用安全模式
     * @return $this
     */
    public function setSafeKey($key): self
    {
        $this->safeKey = $key;

        return $this;
    }

    /**
     * 是否调试模式
     * @return $this
     */
    public function setDebug(): self
    {
        $this->debug = true;

        return $this;
    }

    /**
     * 设置当前Worker实例的名称,方便运行status命令时识别进程
     * 默认为none
     * @param string $name
     * @return $this
     */
    public function setName(string $name = "Workerman Visible Crontab"): self
    {
        $this->worker->name = $name;

        return $this;
    }

    /**
     * 设置当前Worker实例启动多少个进程
     * Worker主进程会 fork出 count个子进程同时监听相同的端口，并行的接收客户端连接，处理连接上的事件
     * 默认为1
     * windows系统不支持此特性
     * @param int $count
     * @return $this
     */
    public function setCount(int $count = 1): self
    {
        $this->worker->count = $count;

        return $this;
    }

    /**
     * 设置当前Worker实例以哪个用户运行
     * 此属性只有当前用户为root时才能生效，建议$user设置权限较低的用户
     * 默认以当前用户运行
     * windows系统不支持此特性
     * @param string $user
     * @return $this
     */
    public function setUser(string $user = "root"): self
    {
        $this->worker->user = $user;

        return $this;
    }

    /**
     * 设置当前Worker实例的协议类
     * 协议处理类可以直接在实例化Worker时在监听参数直接指定
     * @param string $protocol
     * @return $this
     */
    public function setProtocol(string $protocol): self
    {
        $this->worker->protocol = $protocol;

        return $this;
    }

    /**
     * 以daemon(守护进程)方式运行
     * windows系统不支持此特性
     * @return $this
     */
    public function setDaemon(): self
    {
        Worker::$daemonize = true;

        return $this;
    }

    /**
     * 设置所有连接的默认应用层发送缓冲区大小。默认1M。可以动态设置
     * @param float|int $size
     * @return $this
     */
    public function setMaxSendBufferSize($size = 1024 * 1024): self
    {
        TcpConnection::$defaultMaxSendBufferSize = $size;

        return $this;
    }

    /**
     * 设置每个连接接收的数据包。默认10M。超包视为非法数据，连接会断开
     * @param float|int $size
     * @return $this
     */
    public function setMaxPackageSize($size = 10 * 1024 * 1024): self
    {
        TcpConnection::$defaultMaxPackageSize = $size;

        return $this;
    }

    /**
     * 指定日志文件
     * 默认为位于workerman下的 workerman.log
     * 日志文件中仅仅记录workerman自身相关启动停止等日志，不包含任何业务日志
     * @param string $path
     * @return $this
     */
    public function setLogFile(string $path = "./workerman.log"): self
    {
        Worker::$logFile = $path;

        return $this;
    }

    /**
     * 指定打印输出文件
     * 以守护进程方式(-d启动)运行时，所有向终端的输出(echo var_dump等)都会被重定向到 stdoutFile指定的文件中
     * 默认为/dev/null,也就是在守护模式时默认丢弃所有输出
     * windows系统不支持此特性
     * @param string $path
     * @return $this
     */
    public function setStdoutFile(string $path = "./workerman_debug.log"): self
    {
        Worker::$stdoutFile = $path;

        return $this;
    }

    /**
     * 设置数据库链接信息
     * @param array $config
     * @return $this
     */
    public function setDbConfig(array $config = []): self
    {
        $dbConfig = array_change_key_case($config);
        if ($dbConfig['prefix']) {
            $this->systemCrontabTable    = $dbConfig['prefix'] . $this->systemCrontabTable;
            $this->systemCrontabLogTable = $dbConfig['prefix'] . $this->systemCrontabLogTable . '_' . date('Ym');
        }
        return $this;
    }

    /**
     * 注册子进程回调函数
     */
    private function registerCallback()
    {
        $this->worker->onWorkerStart  = [$this, 'onWorkerStart'];
        $this->worker->onWorkerReload = [$this, 'onWorkerReload'];
        $this->worker->onWorkerStop   = [$this, 'onWorkerStop'];
        $this->worker->onConnect      = [$this, 'onConnect'];
        $this->worker->onMessage      = [$this, 'onMessage'];
        $this->worker->onClose        = [$this, 'onClose'];
        $this->worker->onBufferFull   = [$this, 'onBufferFull'];
        $this->worker->onBufferDrain  = [$this, 'onBufferDrain'];
        $this->worker->onError        = [$this, 'onError'];
    }

    /**
     * 设置Worker子进程启动时的回调函数，每个子进程启动时都会执行
     * @param Worker $worker
     */
    public function onWorkerStart(Worker $worker)
    {
        $this->checkCrontabTables();
        $this->crontabInit();
    }

    /**
     * @param Worker $worker
     */
    public function onWorkerStop(Worker $worker)
    {

    }

    /**
     * 设置Worker收到reload信号后执行的回调
     * 如果在收到reload信号后只想让子进程执行onWorkerReload，不想退出，可以在初始化Worker实例时设置对应的Worker实例的reloadable属性为false
     * @param Worker $worker
     */
    public function onWorkerReload(Worker $worker)
    {

    }

    /**
     * 当客户端与Workerman建立连接时(TCP三次握手完成后)触发的回调函数
     * 每个连接只会触发一次onConnect回调
     * 此时客户端还没有发来任何数据
     * 由于udp是无连接的，所以当使用udp时不会触发onConnect回调，也不会触发onClose回调
     * @param TcpConnection $connection
     */
    public function onConnect(TcpConnection $connection)
    {
        $this->checkCrontabTables();
    }

    /**
     * 当客户端连接与Workerman断开时触发的回调函数
     * 不管连接是如何断开的，只要断开就会触发onClose
     * 每个连接只会触发一次onClose
     * 由于udp是无连接的，所以当使用udp时不会触发onConnect回调，也不会触发onClose回调
     * @param TcpConnection $connection
     */
    public function onClose(TcpConnection $connection)
    {

    }

    /**
     * 当客户端通过连接发来数据时(Workerman收到数据时)触发的回调函数
     * @param TcpConnection $connection
     * @param $data
     */
    public function onMessage(TcpConnection $connection, $request)
    {
        if ($request instanceof Request) {
            if (!is_null($this->safeKey) && $request->header('key') !== $this->safeKey) {
                $connection->send($this->response('', 'Connection Not Allowed', 403));
            } else {
                $routeInfo = $this->dispatcher->dispatch($request->method(), $request->path());
                switch ($routeInfo[0]) {
                    case Dispatcher::NOT_FOUND:
                        $connection->send($this->response('', 'Not Found', 404));
                        break;
                    case Dispatcher::METHOD_NOT_ALLOWED:
                        $connection->send($this->response('', 'Method Not Allowed', 405));
                        break;
                    case Dispatcher::FOUND:
                        $connection->send($this->response(call_user_func($routeInfo[1], $request)));
                        break;
                }
            }
        }
    }

    /**
     * 缓冲区满则会触发onBufferFull回调
     * 每个连接都有一个单独的应用层发送缓冲区，如果客户端接收速度小于服务端发送速度，数据会在应用层缓冲区暂存
     * 只要发送缓冲区还没满，哪怕只有一个字节的空间，调用Connection::send($A)肯定会把$A放入发送缓冲区,
     * 但是如果已经没有空间了，还继续Connection::send($B)数据，则这次send的$B数据不会放入发送缓冲区，而是被丢弃掉，并触发onError回调
     * @param TcpConnection $connection
     */
    public function onBufferFull(TcpConnection $connection)
    {

    }

    /**
     * 在应用层发送缓冲区数据全部发送完毕后触发
     * @param TcpConnection $connection
     */
    public function onBufferDrain(TcpConnection $connection)
    {

    }

    /**
     * 客户端的连接上发生错误时触发
     * @param TcpConnection $connection
     * @param $code
     * @param $msg
     */
    public function onError(TcpConnection $connection, $code, $msg)
    {

    }

    /**
     * 初始化定时任务
     * @return void
     */
    private function crontabInit(): void
    {
        $ids = Db::table($this->systemCrontabTable)
            ->where('status', '=', self::NORMAL_STATUS)
            ->order(['sort' => 'desc'])
            ->column('id');

        if (!empty($ids)) {
            foreach ($ids as $id) {
                $this->crontabRun($id);
            }
        }

    }

    /**
     * 定时器列表
     * @param Request $request
     * @return array
     */
    private function crontabIndex(Request $request): array
    {
        list($page, $limit, $where) = $this->buildParames($request->get());
        $data = Db::table($this->systemCrontabTable)
            ->where($where)
            ->order('id', 'desc')
            ->paginate(['list_rows' => $limit, 'page' => $page]);

        return ['data' => $data->items(), 'total' => $data->total()];
    }

    /**
     * 创建定时任务
     * @param Request $request
     * @return bool
     */
    private function crontabCreate(Request $request): bool
    {
        $param                = $request->post();
        $param['create_time'] = $param['update_time'] = time();
        $id                   = Db::table($this->systemCrontabTable)
            ->insertGetId($param);
        $id && $this->crontabRun($id);

        return (bool)$id;
    }

    /**
     * 修改定时器
     * @param Request $request
     * @return bool
     */
    private function crontabModify(Request $request): bool
    {
        $param = $request->post();
        if (in_array($param['field'], ['status', 'sort', 'remark', 'title', 'rule'])) {
            $row = Db::table($this->systemCrontabTable)
                ->where('id', $param['id'])
                ->update([$param['field'] => $param['value']]);

            if ($param['field'] === 'status') {
                if ($param['value'] == self::NORMAL_STATUS) {
                    $this->crontabRun($param['id']);
                } else {
                    if (isset($this->crontabPool[$param['id']])) {
                        $this->crontabPool[$param['id']]['crontab']->destroy();
                        unset($this->crontabPool[$param['id']]);
                    }
                }
            }
            return (bool)$row;
        } else {
            return false;
        }
    }

    /**
     * 清除定时任务
     * @param Request $request
     * @return bool
     */
    private function crontabDelete(Request $request): bool
    {
        if ($id = $request->post('id')) {
            $ids = explode(',', $id);

            foreach ($ids as $item) {
                if (isset($this->crontabPool[$item])) {
                    $this->crontabPool[$item]['crontab']->destroy();
                    unset($this->crontabPool[$item]);
                }
            }

            $rows = Db::table($this->systemCrontabTable)
                ->wherein('id', $id)
                ->delete();

            return (bool)$rows;
        }

        return true;
    }

    /**
     * 重启定时任务
     * @param Request $request
     * @return bool
     */
    private function crontabReload(Request $request): bool
    {
        $ids = explode(',', $request->post('id'));

        foreach ($ids as $id) {
            if (isset($this->crontabPool[$id])) {
                $this->crontabPool[$id]['crontab']->destroy();
                unset($this->crontabPool[$id]);
            }
            Db::table($this->systemCrontabTable)
                ->where('id', $id)
                ->update(['status' => self::NORMAL_STATUS]);
            $this->crontabRun($id);
        }

        return true;
    }

    /**
     * 创建定时器
     * 0   1   2   3   4   5
     * |   |   |   |   |   |
     * |   |   |   |   |   +------ day of week (0 - 6) (Sunday=0)
     * |   |   |   |   +------ month (1 - 12)
     * |   |   |   +-------- day of month (1 - 31)
     * |   |   +---------- hour (0 - 23)
     * |   +------------ min (0 - 59)
     * +-------------- sec (0-59)[可省略，如果没有0位,则最小时间粒度是分钟]
     * @param $id
     */
    private function crontabRun($id)
    {
        $data = Db::table($this->systemCrontabTable)
            ->where('id', $id)
            ->where('status', self::NORMAL_STATUS)
            ->find();

        if (!empty($data)) {
            switch ($data['type']) {
                case self::COMMAND_CRONTAB:
                    $this->crontabPool[$data['id']] = [
                        'id'          => $data['id'],
                        'target'      => $data['target'],
                        'rule'        => $data['rule'],
                        'parameter'   => $data['parameter'],
                        'singleton'   => $data['singleton'],
                        'create_time' => date('Y-m-d H:i:s'),
                        'crontab'     => new Crontab($data['rule'], function () use ($data) {
                            $time      = time();
                            $parameter = $data['parameter'] ?: '';
                            $startTime = microtime(true);
                            $code      = 0;
                            $result    = true;
                            try {
                                if (!empty($parameter)) {
                                    $command = Console::call($data['target'], [$parameter]);
                                } else {
                                    $command = Console::call($data['target']);
                                }
                                $exception = $command->fetch();
                            } catch (\Throwable $e) {
                                $result    = false;
                                $code      = 1;
                                $exception = $e->getMessage();
                            }

                            $this->runInSingleton($data);

                            $this->debug && $this->writeln('执行定时器任务#' . $data['id'] . ' ' . $data['rule'] . ' ' . $data['target'], $result);
                            $endTime = microtime(true);
                            Db::query("UPDATE {$this->systemCrontabTable} SET running_times = running_times + 1, last_running_time = {$time} WHERE id = {$data['id']}");
                            $this->crontabRunLog([
                                'crontab_id'   => $data['id'],
                                'target'       => $data['target'],
                                'parameter'    => $parameter,
                                'exception'    => $exception ?? '',
                                'return_code'  => $code,
                                'running_time' => round($endTime - $startTime, 6),
                                'create_time'  => $time,
                                'update_time'  => $time,
                            ]);

                        })
                    ];
                    break;
                case self::CLASS_CRONTAB:
                    $this->crontabPool[$data['id']] = [
                        'id'          => $data['id'],
                        'target'      => $data['target'],
                        'rule'        => $data['rule'],
                        'parameter'   => $data['parameter'],
                        'singleton'   => $data['singleton'],
                        'create_time' => date('Y-m-d H:i:s'),
                        'crontab'     => new Crontab($data['rule'], function () use ($data) {
                            $time       = time();
                            $class      = trim($data['target']);
                            $method     = 'execute';
                            $parameters = $data['parameter'] ?: '';
                            $startTime  = microtime(true);
                            if ($class && class_exists($class) && method_exists($class, $method)) {
                                try {
                                    $result   = true;
                                    $code     = 0;
                                    $instance = Container::getInstance()->get($class);
                                    if ($parameters && is_array($parameters)) {
                                        $res = $instance->{$method}(...$parameters);
                                    } else {
                                        $res = $instance->{$method}();
                                    }
                                } catch (\Throwable $throwable) {
                                    $result = false;
                                    $code   = 1;
                                }
                                $exception = isset($throwable) ? $throwable->getMessage() : $res;
                            } else {
                                $result    = false;
                                $code      = 1;
                                $exception = "方法或类不存在或者错误";
                            }

                            $this->runInSingleton($data);
                            $this->debug && $this->writeln('执行定时器任务#' . $data['id'] . ' ' . $data['rule'] . ' ' . $data['target'], $result);
                            $endTime = microtime(true);
                            Db::query("UPDATE {$this->systemCrontabTable} SET running_times = running_times + 1, last_running_time = {$time} WHERE id = {$data['id']}");
                            $this->crontabRunLog([
                                'crontab_id'   => $data['id'],
                                'target'       => $data['target'],
                                'parameter'    => $parameters ?? '',
                                'exception'    => $exception ?? '',
                                'return_code'  => $code,
                                'running_time' => round($endTime - $startTime, 6),
                                'create_time'  => $time,
                                'update_time'  => $time,
                            ]);

                        })
                    ];
                    break;
                case self::URL_CRONTAB:
                    $this->crontabPool[$data['id']] = [
                        'id'          => $data['id'],
                        'target'      => $data['target'],
                        'rule'        => $data['rule'],
                        'parameter'   => $data['parameter'],
                        'singleton'   => $data['singleton'],
                        'create_time' => date('Y-m-d H:i:s'),
                        'crontab'     => new Crontab($data['rule'], function () use ($data) {
                            $time      = time();
                            $url       = trim($data['target']);
                            $startTime = microtime(true);
                            $client    = new \GuzzleHttp\Client();
                            try {
                                $response = $client->get($url);
                                $result   = $response->getStatusCode() === 200;
                                $code     = 0;
                            } catch (\Throwable $throwable) {
                                $result    = false;
                                $code      = 1;
                                $exception = $throwable->getMessage();
                            }

                            $this->runInSingleton($data);

                            $this->debug && $this->writeln('执行定时器任务#' . $data['id'] . ' ' . $data['rule'] . ' ' . $data['target'], $result);
                            $endTime = microtime(true);
                            Db::query("UPDATE {$this->systemCrontabTable} SET running_times = running_times + 1, last_running_time = {$time} WHERE id = {$data['id']}");
                            $this->crontabRunLog([
                                'crontab_id'   => $data['id'],
                                'target'       => $data['target'],
                                'parameter'    => $data['parameter'] ?? '',
                                'exception'    => $exception ?? '',
                                'return_code'  => $code,
                                'running_time' => round($endTime - $startTime, 6),
                                'create_time'  => $time,
                                'update_time'  => $time,
                            ]);

                        })
                    ];
                    break;
                case self::SHELL_CRONTAB:
                    $this->crontabPool[$data['id']] = [
                        'id'          => $data['id'],
                        'target'      => $data['target'],
                        'rule'        => $data['rule'],
                        'parameter'   => $data['parameter'],
                        'singleton'   => $data['singleton'],
                        'create_time' => date('Y-m-d H:i:s'),
                        'crontab'     => new Crontab($data['rule'], function () use ($data) {
                            $time      = time();
                            $parameter = $data['parameter'] ?: '';
                            $startTime = microtime(true);
                            $code      = 0;
                            $result    = true;
                            try {
                                $exception = shell_exec($data['target']);
                            } catch (\Throwable $e) {
                                $result    = false;
                                $code      = 1;
                                $exception = $e->getMessage();
                            }

                            $this->runInSingleton($data);

                            $this->debug && $this->writeln('执行定时器任务#' . $data['id'] . ' ' . $data['rule'] . ' ' . $data['target'], $result);
                            $endTime = microtime(true);
                            Db::query("UPDATE {$this->systemCrontabTable} SET running_times = running_times + 1, last_running_time = {$time} WHERE id = {$data['id']}");
                            $this->crontabRunLog([
                                'crontab_id'   => $data['id'],
                                'target'       => $data['target'],
                                'parameter'    => $parameter,
                                'exception'    => $exception ?? '',
                                'return_code'  => $code,
                                'running_time' => round($endTime - $startTime, 6),
                                'create_time'  => $time,
                                'update_time'  => $time,
                            ]);

                        })
                    ];
                    break;
            }
        }
    }

    /**
     * 是否单次
     * @param $crontab
     * @return void
     */
    private function runInSingleton($crontab)
    {
        if ($crontab['singleton'] === 0) {
            $this->crontabPool[$crontab['id']]['crontab']->destroy();
        }
    }

    /**
     * 定时器池
     * @return array
     */
    private function crontabPool(): array
    {
        $data = [];
        foreach ($this->crontabPool as $row) {
            unset($row['crontab']);
            $data[] = $row;
        }

        return $data;
    }

    /**
     * 心跳
     * @return string
     */
    private function crontabPong(): string
    {
        return 'pong';
    }

    /**
     * 执行日志
     * @param Request $request
     * @return array
     */
    private function crontabFlow(Request $request): array
    {
        $crontab_id = $request->get('crontab_id');
        list($page, $limit, $where, $excludeFields) = $this->buildParames($request->get(), ['month']);
        $crontab_id && $where[] = ['crontab_id', '=', $request->get('crontab_id')];
        $allTables = $this->getDbTables();
        $tableName = isset($excludeFields['month']) && !empty($excludeFields['month']) ?
            preg_replace('/_\d+/', '_' . date('Ym', strtotime($excludeFields['month'])), $this->systemCrontabLogTable) :
            $this->systemCrontabLogTable;
        $data      = [];
        if (in_array($tableName, $allTables)) {
            $data = Db::table($tableName)
                ->where($where)
                ->order(['id' => 'desc'])
                ->paginate(['list_rows' => $limit, 'page' => $page]);
        }

        return ['data' => $data->items(), 'total' => $data->total()];
    }

    /**
     * 记录执行日志
     * @param array $data
     * @return void
     */
    private function crontabRunLog(array $data): void
    {
        Db::table($this->systemCrontabLogTable)->insert($data);
    }

    /**
     * 函数是否被禁用
     * @param $method
     * @return bool
     */
    private function functionDisabled($method): bool
    {
        return in_array($method, explode(',', ini_get('disable_functions')));
    }

    /**
     * 扩展是否加载
     * @param $extension
     * @return bool
     */
    private function extensionLoaded($extension): bool
    {
        return in_array($extension, get_loaded_extensions());
    }

    /**
     * 是否是Linux操作系统
     * @return bool
     */
    private function isLinux(): bool
    {
        return strpos(PHP_OS, "Linux") !== false;
    }

    /**
     * 版本比较
     * @param $version
     * @param string $operator
     * @return bool
     */
    private function versionCompare($version, string $operator = ">="): bool
    {
        return version_compare(phpversion(), $version, $operator);
    }

    /**
     * 检测运行环境
     */
    private function checkEnv()
    {
        $errorMsg = [];
        $this->functionDisabled('exec') && $errorMsg[] = 'exec函数被禁用';
        if ($this->isLinux()) {
            $this->versionCompare($this->lessPhpVersion, '<') && $errorMsg[] = 'PHP版本必须≥' . $this->lessPhpVersion;
            $checkExt = ["pcntl", "posix"];
            foreach ($checkExt as $ext) {
                !$this->extensionLoaded($ext) && $errorMsg[] = $ext . '扩展没有安装';
            }
            $checkFunc = [
                "stream_socket_server",
                "stream_socket_client",
                "pcntl_signal_dispatch",
                "pcntl_signal",
                "pcntl_alarm",
                "pcntl_fork",
                "posix_getuid",
                "posix_getpwuid",
                "posix_kill",
                "posix_setsid",
                "posix_getpid",
                "posix_getpwnam",
                "posix_getgrnam",
                "posix_getgid",
                "posix_setgid",
                "posix_initgroups",
                "posix_setuid",
                "posix_isatty",
            ];
            foreach ($checkFunc as $func) {
                $this->functionDisabled($func) && $errorMsg[] = $func . '函数被禁用';
            }
        }

        if (!empty($errorMsg)) {
            $this->errorMsg = array_merge($this->errorMsg, $errorMsg);
        }
    }

    /**
     * 输出日志
     * @param $msg
     * @param bool $ok
     */
    private function writeln($msg, bool $ok = true)
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . ($ok ? " [Ok] " : " [Fail] ") . PHP_EOL;
    }

    /**
     * 检测表是否存在
     */
    private function checkCrontabTables()
    {
        $date = date('Ym', time());
        if ($date !== $this->systemCrontabLogTableSuffix) {
            $this->systemCrontabLogTableSuffix = $date;
            $this->systemCrontabLogTable       = preg_replace('/_\d+/', '_' . $date, $this->systemCrontabLogTable);
            $allTables                         = $this->getDbTables();
            !in_array($this->systemCrontabTable, $allTables) && $this->createSystemCrontabTable();
            !in_array($this->systemCrontabLogTable, $allTables) && $this->createSystemCrontabLogTable();
        }
    }

    /**
     * 创建定时器任务表
     */
    private function createSystemCrontabTable()
    {
        $sql = <<<SQL
 CREATE TABLE IF NOT EXISTS `{$this->systemCrontabTable}`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '任务标题',
  `type` tinyint(1) NOT NULL DEFAULT 1 COMMENT '任务类型 (1 command, 2 class, 3 url 4 shell)',
  `rule` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '任务执行表达式',
  `target` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '调用任务字符串',
  `parameter` varchar(500) NOT NULL COMMENT '任务调用参数', 
  `running_times` int(11) NOT NULL DEFAULT '0' COMMENT '已运行次数',
  `last_running_time` int(11) NOT NULL DEFAULT '0' COMMENT '上次运行时间',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '备注',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序，越大越前',
  `status` tinyint(4) NOT NULL DEFAULT 0 COMMENT '任务状态状态[0:禁用;1启用]',
  `singleton` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否单次执行 (0 是 1 不是)',
  `create_time` int(11) NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `title`(`title`) USING BTREE,
  INDEX `type`(`type`) USING BTREE,
  INDEX `create_time`(`create_time`) USING BTREE,
  INDEX `status`(`status`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '定时器任务表' ROW_FORMAT = DYNAMIC
SQL;

        return Db::query($sql);
    }

    /**
     * 定时器任务流水表
     */
    private function createSystemCrontabLogTable()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `{$this->systemCrontabLogTable}`  (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `crontab_id` bigint UNSIGNED NOT NULL COMMENT '任务id',
  `target` varchar(255) NOT NULL COMMENT '任务调用目标字符串',
  `parameter` varchar(500) NOT NULL COMMENT '任务调用参数', 
  `exception` text NOT NULL COMMENT '任务执行或者异常信息输出',
  `return_code` tinyint(1) NOT NULL DEFAULT 0 COMMENT '执行返回状态[0成功; 1失败]',
  `running_time` varchar(10) NOT NULL COMMENT '执行所用时间',
  `create_time` int(11) NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `create_time`(`create_time`) USING BTREE,
  INDEX `crontab_id`(`crontab_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '定时器任务流水表{$this->systemCrontabLogTableSuffix}' ROW_FORMAT = DYNAMIC
SQL;

        return Db::query($sql);
    }

    /**
     * 获取数据库表名
     * @return array
     */
    private function getDbTables(): array
    {
        return Db::getTables();
    }

    private function response($data = '', $msg = '信息调用成功！', $code = 200): Response
    {
        return new Response($code, [
            'Content-Type' => 'application/json; charset=utf-8',
        ], json_encode(['code' => $code, 'data' => $data, 'msg' => $msg]));
    }

    /**
     * 构建请求参数
     * @param array $get
     * @param array $excludeFields 忽略构建搜索的字段
     * @return array
     */
    private function buildParames($get, $excludeFields = [])
    {
        $page    = isset($get['page']) && !empty($get['page']) ? (int)$get['page'] : 1;
        $limit   = isset($get['limit']) && !empty($get['limit']) ? (int)$get['limit'] : 15;
        $filters = isset($get['filter']) && !empty($get['filter']) ? $get['filter'] : '{}';
        $ops     = isset($get['op']) && !empty($get['op']) ? $get['op'] : '{}';
        // json转数组
        $filters  = json_decode($filters, true);
        $ops      = json_decode($ops, true);
        $where    = [];
        $excludes = [];

        foreach ($filters as $key => $val) {
            if (in_array($key, $excludeFields)) {
                $excludes[$key] = $val;
                continue;
            }
            $op = isset($ops[$key]) && !empty($ops[$key]) ? $ops[$key] : '%*%';

            switch (strtolower($op)) {
                case '=':
                    $where[] = [$key, '=', $val];
                    break;
                case '%*%':
                    $where[] = [$key, 'like', "%{$val}%"];
                    break;
                case '*%':
                    $where[] = [$key, 'like', "{$val}%"];
                    break;
                case '%*':
                    $where[] = [$key, 'like', "%{$val}"];
                    break;
                case 'range':
                    list($beginTime, $endTime) = explode(' - ', $val);
                    $where[] = [$key, 'between', [strtotime($beginTime), strtotime($endTime)]];
                    break;
                case 'in':
                    $where[] = [$key, 'in', $val];
                    break;
                default:
                    $where[] = [$key, $op, "%{$val}"];
            }
        }

        return [$page, $limit, $where, $excludes];
    }


    /**
     * 运行所有Worker实例
     * Worker::runAll()执行后将永久阻塞
     * Worker::runAll()调用前运行的代码都是在主进程运行的，onXXX回调运行的代码都属于子进程
     * windows版本的workerman不支持在同一个文件中实例化多个Worker
     * windows版本的workerman需要将多个Worker实例初始化放在不同的文件中
     */
    public function run()
    {
        if (empty($this->errorMsg)) {
            $this->writeln("启动系统任务");
            Worker::runAll();
        } else {
            foreach ($this->errorMsg as $v) {
                $this->writeln($v, false);
            }
        }
    }
}
