<?php

namespace Fairy\command;

use Fairy\HttpCrontab;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

class Crontab extends Command
{
    protected function configure()
    {
        $this->setName('crontab')
            ->addArgument('action', Argument::REQUIRED, 'start|stop|restart|reload|status|connections')
            ->addOption('daemon', 'd', Option::VALUE_NONE, 'Run the http crontab server in daemon mode.')
            ->addOption('debug', null, Option::VALUE_NONE, 'Print log')
            ->setDescription('Run http crontab server');
    }

    protected function execute(Input $input, Output $output)
    {
        $action = trim($input->getArgument('action'));
        if (!in_array($action, ['start', 'stop', 'restart', 'reload', 'status', 'connections'])) {
            $this->output->writeln('action参数值非法');
            return false;
        }
        $options = $input->getOptions();
        $config  = config('crontab');
        if ($config['base_url'] && !preg_match('/https?:\/\//', $config['base_url'])) {
            $this->output->writeln('crontab base_url 配置值非法');
            return false;
        }

        $server   = new HttpCrontab($config['base_url']);
        $database = config('database.connections.mysql');
        $server->setName($config['name'])
            ->setDbConfig($database ?? []);
        if ($config['safe_key']) {
            $server->setSafeKey($config['safe_key']);
        }
        if ($config['table']) {
            $server->setTaskTable($config['table']['task'])
                ->setTaskLogTable($config['table']['task_log']);
        }
        $options['debug'] && $server->setDebug();
        $server->run();
    }
}