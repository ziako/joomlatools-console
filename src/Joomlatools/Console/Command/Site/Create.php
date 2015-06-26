<?php
/**
 * @copyright	Copyright (C) 2007 - 2015 Johan Janssens and Timble CVBA. (http://www.timble.net)
 * @license		Mozilla Public License, version 2.0
 * @link		http://github.com/joomlatools/joomla-console for the canonical source repository
 */

namespace Joomlatools\Console\Command\Site;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Joomlatools\Console\Command\Database;

class Create extends Database\AbstractDatabase
{
    /**
     * Clear cache before fetching versions
     * @var bool
     */
    protected $clear_cache = false;

    protected $template;

    /**
     * Joomla version to install
     *
     * @var string
     */
    protected $version;

    /**
     * Projects to symlink
     * @var array
     */
    protected $symlink = array();

    protected function configure()
    {
        parent::configure();

        $this
            ->setName('site:create')
            ->setDescription('Create a Joomla site')
            ->addOption(
                'joomla',
                null,
                InputOption::VALUE_REQUIRED,
                "Joomla version. Can be a release number (2, 3.2, ..) or branch name. Run `joomla versions` for a full list.\nUse \"none\" for an empty virtual host.",
                'latest'
            )
            ->addOption(
                'sample-data',
                null,
                InputOption::VALUE_REQUIRED,
                'Sample data to install (default|blog|brochure|learn|testing)'
            )
            ->addOption(
                'symlink',
                null,
                InputOption::VALUE_REQUIRED,
                'A comma separated list of folders to symlink from projects folder'
            )
            ->addOption(
                'clear-cache',
                null,
                InputOption::VALUE_NONE,
                'Update the list of available tags and branches from the Joomla repository'
            )
            ->addOption(
                'projects-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Directory where your custom projects reside',
                sprintf('%s/Projects', trim(`echo ~`))
            )
            ->addOption(
                'disable-ssl',
                null,
                InputOption::VALUE_NONE,
                'Disable SSL for this site'
            )
            ->addOption(
                'ssl-crt',
                null,
                InputOption::VALUE_OPTIONAL,
                'The full path to the signed cerfificate file',
                '/etc/apache2/ssl/server.crt'
            )
            ->addOption(
                'ssl-key',
                null,
                InputOption::VALUE_OPTIONAL,
                'The full path to the private cerfificate file',
                '/etc/apache2/ssl/server.key'
            )
            ->addOption(
                'ssl-port',
                null,
                InputOption::VALUE_OPTIONAL,
                'The port on which the server will listen for SSL requests',
                '443'
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);

        $this->symlink = $input->getOption('symlink');
        if (is_string($this->symlink)) {
            $this->symlink = explode(',', $this->symlink);
        }

        $this->version = $input->getOption('joomla');

        $this->check($input, $output);

        `mkdir -p $this->target_dir`;

        $this->download($input, $output);
        $this->importdb($input, $output);
        $this->createConfig($input, $output);

        $this->addVirtualHost($input, $output);
        $this->symlinkProjects($input, $output);
        $this->installExtensions($input, $output);
        $this->enableWebInstaller($input, $output);

        if ($this->version != 'none')
        {
            $output->writeln("Your new Joomla site has been created.");
            $output->writeln("You can login using the following username and password combination: <info>admin</info>/<info>admin</info>.");
        }
    }

    public function check(InputInterface $input, OutputInterface $output)
    {
        if (file_exists($this->target_dir)) {
            throw new \RuntimeException(sprintf('A site with name %s already exists', $this->site));
        }
    }

    public function download(InputInterface $input, OutputInterface $output)
    {
        $command_input = new ArrayInput(array(
            'site:download',
            'site'          => $this->site,
            '--joomla'      => $input->getOption('joomla'),
            '--clear-cache' => $input->getOption('clear-cache')
        ));

        $command = new Download();
        $command->run($command_input, $output);
    }

    public function importdb(InputInterface $input, OutputInterface $output)
    {
        if ($this->version == 'none') {
            return;
        }

        $arguments = array(
            'site:database:install',
            'site'          => $this->site
        );

        $sample_data = $input->getOption('sample-data');
        if (!empty($sample_data)) {
            $arguments['--sample-data'] = $sample_data;
        }

        $command = new Database\Install();
        $command->run(new ArrayInput($arguments), $output);
    }

    public function createConfig(InputInterface $input, OutputInterface $output)
    {
        if ($this->version == 'none') {
            return;
        }

        $command_input = new ArrayInput(array(
            'site:configure',
            'site'          => $this->site
        ));

        $command = new Configure();
        $command->run($command_input, $output);
    }

    public function addVirtualHost(InputInterface $input, OutputInterface $output)
    {
        if (is_dir('/etc/apache2/sites-available'))
        {
            $tmp = self::$files.'/.vhost.tmp';

            $template = file_get_contents(self::$files.'/vhost.conf');

            file_put_contents($tmp, sprintf($template, $this->site));

            if (!$input->getOption('disable-ssl'))
            {
                $ssl_crt = $input->getOption('ssl-crt');
                $ssl_key = $input->getOption('ssl-key');
                $ssl_port = $input->getOption('ssl-port');

                if (file_exists($ssl_crt) && file_exists($ssl_key))
                {
                    $template = "\n\n" . file_get_contents(self::$files . '/vhost.ssl.conf');
                    file_put_contents($tmp, sprintf($template, $ssl_port, $this->site, $ssl_crt, $ssl_key), FILE_APPEND);
                }
                else $output->writeln('<comment>SSL was not enabled for the site. One or more certificate files are missing.</comment>');
            }

            `sudo tee /etc/apache2/sites-available/1-$this->site.conf < $tmp`;
            `sudo a2ensite 1-$this->site.conf`;
            `sudo /etc/init.d/apache2 restart > /dev/null 2>&1`;

            @unlink($tmp);
        }
    }

    public function symlinkProjects(InputInterface $input, OutputInterface $output)
    {
        if ($this->symlink)
        {
            $symlink_input = new ArrayInput(array(
                'site:symlink',
                'site'    => $input->getArgument('site'),
                'symlink' => $this->symlink,
                '--www'   => $this->www,
                '--projects-dir' => $input->getOption('projects-dir')
            ));
            $symlink = new ExtensionSymlink();

            $symlink->run($symlink_input, $output);
        }
    }

    public function installExtensions(InputInterface $input, OutputInterface $output)
    {
        if ($this->symlink)
        {
            $extension_input = new ArrayInput(array(
                'extension:install',
                'site'      => $input->getArgument('site'),
                'extension' => $this->symlink,
                '--www'     => $this->www
            ));
            $installer = new ExtensionInstall();

            $installer->run($extension_input, $output);
        }
    }

    public function enableWebInstaller(InputInterface $input, OutputInterface $output)
    {
        if ($this->version == 'none') {
            return;
        }

        $version = $this->_getJoomlaVersion();

        if ($this->version != 'latest' && version_compare($version, '3.2.0', '<')) {
            return;
        }

        $xml = simplexml_load_file('http://appscdn.joomla.org/webapps/jedapps/webinstaller.xml');

        if(!$xml)
        {
            $output->writeln('<warning>Failed to install web installer</warning>');

            return;
        }

        $url = '';
        foreach($xml->update->downloads->children() as $download)
        {
            $attributes = $download->attributes();
            if($attributes->type == 'full' && $attributes->format == 'zip')
            {
                $url = (string) $download;
                break;
            }
        }

        if(empty($url)) {
            return;
        }

        $filename = self::$files.'/cache/'.basename($url);
        if(!file_exists($filename))
        {
            $bytes = file_put_contents($filename, fopen($url, 'r'));
            if($bytes === false || $bytes == 0) {
                return;
            }
        }

        `mkdir -p $this->target_dir/plugins/installer`;
        `cd $this->target_dir/plugins/installer/ && unzip -o $filename`;

        $sql = "INSERT INTO `j_extensions` (`name`, `type`, `element`, `folder`, `enabled`, `access`, `manifest_cache`) VALUES ('plg_installer_webinstaller', 'plugin', 'webinstaller', 'installer', 1, 1, '{\"name\":\"plg_installer_webinstaller\",\"type\":\"plugin\",\"version\":\"".$xml->update->version."\",\"description\":\"Web Installer\"}');";
        $sql = escapeshellarg($sql);

        $password = empty($this->mysql->password) ? '' : sprintf("-p'%s'", $this->mysql->password);
        exec(sprintf("mysql -u'%s' %s %s -e %s", $this->mysql->user, $password, $this->target_db, $sql));
    }
}
