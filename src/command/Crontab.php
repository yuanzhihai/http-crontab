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
            ->addOption('name', null, Option::VALUE_OPTIONAL, 'Crontab name', 'Crontab Server')
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
        $url     = '';
        if (config('crontab.base_url') !== null && config('crontab.base_url')) {
            if (!preg_match('/https?:\/\//', config('crontab.base_url'))) {
                $this->output->writeln('crontab base_url 配置值非法');
                return false;
            }
            $url = config('crontab.base_url');
        }

        $server   = new HttpCrontab($url);
        $database = config('database.connections.mysql');
        $server->setName($options['name'])
            ->setDbConfig($database ?? []);
        if (config('crontab.safe_key') !== null && config('crontab.safe_key')) {
            $server->setSafeKey(config('crontab.safe_key'));
        }
        $options['debug'] && $server->setDebug();
        $server->run();
    }
}