#!/usr/bin/php
<?php
define('_JEXEC', 1);

/**
 * Turn on strict error reporting during development
 */
ini_set('display_errors', '1');
ini_set('error_reporting', E_ALL | E_STRICT);

/**
 * Bootstrap the Joomla! Platform.
 */
//require getenv('JOOMLA_PLATFORM_PATH').'/libraries/import.php';
$myPlatform = '/home/elkuku/phpstormspace/debug4/joomla-platform';
require $myPlatform.'/libraries/import.php';

define('JPATH_BASE', dirname(__FILE__));
define('JPATH_SITE', JPATH_BASE);

jimport('joomla.application.cli');
jimport('joomla.database');
jimport('joomla.database.table');
jimport('joomla.filesystem.file');
jimport('joomla.filesystem.folder');

require 'formatter.php';

//require_once getenv('JOOMLA_PLATFORM_PATH').'/libraries/joomla/database/database/mysqliexporter.php';
require_once $myPlatform.'/libraries/joomla/database/database/mysqliexporter.php';

JError::$legacy = false;

/**
 * XML2SQL
 */
class XML2SQL extends JApplicationCli
{
    /**
     * @var Xml2SqlFormatter
     */
    private $formatter = null;

    /**
     * Execute the application.
     *
     * @return  void
     */
    public function execute()
    {
        $this->out('|-------------------------|');
        $this->out('|          XML2SQL        |');
        $this->out('|                         |');
        $this->out('|   2011 by NiK (elkuku)  |');
        $this->out('|-------------------------|');
        $this->out();

        $this->dbOptions = array(
            'driver' => $this->get('dbtype', 'mysqli'),
            'host' => $this->get('host'),
            'user' => $this->get('user'),
            'password' => $this->get('password'),
            'database' => $this->get('db'),
            'prefix' => $this->get('dbprefix'),
            'select' => false,
        );

	    $sampleData =($this->input->get('sampledata')) ? '.sampledata' : '';

        if($this->input->get('create'))
        {
            $this->create();
        }
        elseif($this->input->get('createall'))
        {
            $this->create();

            $input = 'xml2sql-created.xml';

            foreach (new DirectoryIterator(JPATH_BASE.'/formats') as $fInfo)
            {
                if($fInfo->isDot())
                continue;

                $format = $fInfo->getBasename('.php');

                $output = 'xml2sql-created.'.$format.$sampleData.'.sql';

                $this->createSql($input, $output, $format);
            }//foreach
        }
        else
        {
            $input = $this->input->get('i', '', 'string');
            $output = $this->input->get('o', '', 'string');
            $format = $this->input->get('format');

            $this->createSql($input, $output, $format);
        }

        $this->out();
        $this->out('Finished =;)');
        $this->out();
    }//function

    private function createSql($input, $output, $format)
    {
        if( ! $input || ! $output || ! $format)
        throw new Exception('Missing values. Usage: -i <inputfile> -o <outputfile> --format <format>', 1);

        $this->out();
        $this->out('Format: '.$format);
        $this->out('Input:  '.$input);
        $this->out('Output: '.$output);
        $this->out();

        $path = JPATH_BASE.'/formats/'.$format.'.php';

        if( ! file_exists($path))
        throw new Exception('Format file not found: '.$path, 1);

        require $path;

        $className = 'Xml2SqlFormat'.ucfirst($format);

        if( ! class_exists($className))
        throw new Exception(sprintf('Required class "%1$s" not found in file %2$s', $className, $path), 1);

        $prefix = $this->input->get('prefix', 'xxxxx_');

        $options = array(
            'prefix' => $prefix,
        );

        $this->formatter = new $className($options);

        $xml = simplexml_load_file($input);

        if( ! $xml)
        throw new Exception('Invalid xml file: '.$input, 1);

        $sql = '';
        $cnt = 0;

        if($this->input->get('sampledata'))
        {
            // To install the sample data, first we empty all the tables
            foreach ($xml->database->table_structure as $tableStructure)
            {
                $sql .= $this->formatter->formatTruncate($tableStructure);
                $cnt ++;
            }//foreach

            $this->out(sprintf('Processed %d truncate table queries.', $cnt));
        }
        else
        {
            //-- Process "CREATE TABLE" stanetments
            foreach ($xml->database->table_structure as $create)
            {
                $sql .= $this->formatter->formatCreate($create);
                $cnt ++;
            }//foreach

            $this->out(sprintf('Processed %d create queries.', $cnt));
        }

        $cnt = 0;

        //-- Process "INSERT" statements
        foreach ($xml->database->table_data as $insert)
        {
            $sql .= $this->formatter->formatInsert($insert);
            $cnt ++;
        }//foreach

        $this->out(sprintf('Processed %d insert queries.', $cnt));

        if( ! JFile::write($output, $sql))
        throw new Exception('Unable to generate the output file at: '.$output, 1);

        $this->out(sprintf('Output file created at: %s', $output));
    }

    private function create()
    {
        $jBase = $this->get('jbase');

        $this->out('JBase:'.$jBase);

        if( ! is_dir($jBase))
        throw new Exception('Invalid $jbase set in configuration.php');

        if( ! is_dir($jBase.'/installation'))
        throw new Exception('No installation dir found in specified $jbase dir set in configuration.php');

        $this->out('Create db...', false);

        $db = JDatabase::getInstance($this->dbOptions);

        $db->setQuery('DROP DATABASE IF EXISTS '.$this->get('db'))->query();

        $db->setQuery('CREATE DATABASE '.$this->get('db'))->query();

        $db->setQuery('USE '.$this->get('db'))->query();

        $this->out('ok');

        $this->out('Fill db...', false);

        $sql = JFile::read($jBase.'/installation/sql/mysql/joomla.sql');

        if($this->input->get('sampledata'))
        {
            $this->out('with sample data...', false);
            $sql .= JFile::read($jBase.'/installation/sql/mysql/sample_data.sql');
        }

        $queries = $db->splitSql($sql);

        $this->out(sprintf('Found %d queries...', count($queries)), false);

        foreach ($queries as $i => $query)
        {
            if($i && $i / 10 == floor($i / 10))
            $this->out($i.'...', false);

            $query = trim($query);

            if( ! $query)
            continue;

            $db->setQuery($query)->query();
        }

        $this->out(($i + 1).'...', false);

        $this->out('ok');

        $this->out('dump db to XML...', false);

        $this->dbOptions['select'] = true;

        $tables = $db->getTableList();

        $exporter = new JDatabaseExporterMySQLi;

        $contents = (string)$exporter->setDbo($db)->from($tables)->withData()->asXml();
$contents = (string)$db->getExporter()->from($db->getTableList())->withData();
        if( ! JFile::write(JPATH_BASE.'/xml2sql-created.xml', $contents))
            throw new Exception('Can not write output file to: '.JPATH_BASE.'/xml2sql-created.xml');

        $this->out('ok');

        $this->out('delete db...', false);

        $db->setQuery('DROP DATABASE '.$this->get('db'))->query();

        $this->out('ok');

        return $this;

        /*

        $connData = '';

        $connData .= ' -u '.$this->get('user');
        $connData .= ' -h '.$this->get('host');

        if($this->get('password'))
        $connData .= ' -p '.$config->get('password');

        $connection = $this->get('mysqlpath').'/mysqldump --xml '.$connData;

        $cmd = $connection
        .' '.$this->get('db')
        .' > xml2sql-created.xml';

        echo shell_exec($cmd);

        $this->out('ok');

        $this->out('delete db...', false);

        $db->setQuery('DROP DATABASE '.$this->get('db'))->query();

        $this->out('ok');
        */
    }

}//class

try
{
    // Execute the application.
    JApplicationCli::getInstance('XML2SQL')->execute();

    exit(0);
}
catch (Exception $e)
{
    // An exception has been caught, just echo the message.
    fwrite(STDOUT, $e->getMessage() . "\n");

    exit($e->getCode());
}//try
