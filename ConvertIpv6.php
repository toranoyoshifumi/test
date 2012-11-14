<?php

/**
 * Description of ConvertIpv6 Command
 *
 * @author yoshifumi.uetake
 * @package ControlCenterManagement
 */
class Gree_Service_Cc_Management_Cli_GeoIp_Command_Convertipv6
        extends Gree_Service_Cc_Management_Cli_GeoIp_Command_AbstractBase
{
    /**
     * Upper bound parcentage of (split count / all)
     */
    const SPLIT_UPPER_BOUND = 1;

    /**
     * @var SplFileInfo
     */
    protected $ipv6;

    protected function getName()
    {
        return 'convertipv6';
    }

    protected function getArguments()
    {
        return array(
            'file' => array(
                'description' => 'target GeoIP City IPv6 csv file',
            ),
        );
    }

    protected function initialize(
            Console_CommandLine_Result $result, $is_dry_run) {

        $this->ipv6 = new SplFileInfo(realpath($result->args['file']));

        printf(
                "convert this file.\n".
                "ipv6:      %s\n",
                $this->ipv6->getPathname()
        );
    }

    protected function execute(Console_CommandLine_Result $result, $is_dry_run, $is_force_run)
    {
        $this->convert($is_dry_run, $this->ipv6, array($this, 'convertIpv6'));
    }

    /**
     * @param bool $is_dry_run
     * @param SplFileInfo $input
     * @param callable $convert
     * @return void
     */
    protected function convert($is_dry_run, SplFileInfo $input, $convert)
    {
        printf("converting %s.\n", $input->getFilename());

        $reader = $this->getReader($input, 0);
        $output       = $this->getOutputFile($is_dry_run, $input, 'ipv6.csv');
        $split_output = $this->getOutputFile($is_dry_run, $input, 'split.csv');

        $buffer = $split_buffer = '';
        $split_count = 0;
        foreach ($reader as $i => $row) {
            unset($row[2], $row[3], $row[5], $row[6], $row[9], $row[10], $row[11]);
            $row = array_values($row);

            if ($this->shouldSplit($row[0], $row[1])) {
                $split_count++;

                $split_buffer .= $this->convertSplit($row);
                $split_output->fwrite($split_buffer);
                $split_buffer = '';
            }

            $buffer .= call_user_func($convert, $row);
            if (($i % 1000) === 0) {
                if (false === $is_dry_run) {
                    $output->fwrite($buffer);
                }
                $buffer = '';
                printf("\r%.2f%%", $reader->getProgress());
            }

        }
        if (false === $is_dry_run) {
            $output->fwrite($buffer);
        }

        $this->checkThreshold($split_count, $i);

        print "\r";
        print "success.\n";
        printf("check out %s\n", $output->getPathname());
        printf("          %s\n\n", $split_output->getPathname());
    }

    /*
     * @param int $split_count
     * @param int $all_count
     */
    protected function checkThreshold($split_count, $all_count)
    {
        if ($split_count * 100 / $all_count > self::SPLIT_UPPER_BOUND) {
            throw new Exception(
                sprintf("Warning:%f%% of entries (%d of %d) is exceptions. Please check csv file",
                        $split_count * 100 / $all_count, $split_count, $all_count)
            );
        }
    }

    /**
     * @param array $row
     * @return string
     */
    protected function convertIpv6(array $row)
    {
        $this->shouldSplit($row[0], $row[1]) ? $row[5] = 1 : $row[5] = 0;
        $row[0] = base_convert(substr(Gree_Service_Cc_Foundation_Network_IPvx::ipvx_ptob($row[0]), 0, 48), 2, 10);
        $row[1] = base_convert(substr(Gree_Service_Cc_Foundation_Network_IPvx::ipvx_ptob($row[1]), 0, 48), 2, 10);


        foreach ($row as $key => $value) {
            $row[$key] = $value === null ? 'NULL' : "\"$value\"";
        }
        return implode(',', $row) . "\n";

    }

    /**
     * @param array $row
     * @return string
     */
    protected function convertSplit(array $row)
    {
        $binary_number = Gree_Service_Cc_Foundation_Network_IPvx::ipvx_ptob($row[0]);
        array_unshift($row, '');
        $row[0] = base_convert(substr($binary_number, 0, 48), 2, 10);
        $row[1] = base_convert(substr($binary_number, 48, 32), 2, 10);
        $row[2] = base_convert(substr(Gree_Service_Cc_Foundation_Network_IPvx::ipvx_ptob($row[2]), 48, 32), 2, 10);

        foreach ($row as $key => $value) {
            $row[$key] = $value === NULL ? 'NULL' : "\"$value\"";
        }
        return implode(',', $row) . "\n";
    }


    /**
     * @param string $start_address
     * @param string $end_address
     */
    protected function shouldSplit($start_address, $end_address)
    {
        $end_binary = Gree_Service_Cc_Foundation_Network_IPvx::ipvx_ptob($end_address);

        if (substr_count($start_address, ':') > 6 ) {
            $msg = "'$start_address' valuefalls outside the range of values accepted by the logic.";
            throw new InvalidArgumentException($msg);
        }
        if (strpos(substr($end_binary, 80, 48), '0') !== false) {
            $msg = "'$end_address' valuefalls outside the range of values accepted by the logic.";
            throw new InvalidArgumentException($msg);
        }

        if (substr_count($start_address, ':') > 4 || strpos(substr($end_binary, 48, 32), '0') !== false) {
            return true;
        }
        return false;
    }

    /**
     * @param SplFileInfo $input
     * @param int skip_count
     * @return Gree_Service_Cc_Management_GeoIp_Reader
     */
    protected function getReader(SplFileInfo $input, $skip_count)
    {
        return new Gree_Service_Cc_Management_Cli_GeoIp_CsvFileReader($input, $skip_count);
    }

    /**
     * @param bool $is_dry_run
     * @param SplFileInfo $input
     * @return SplFileObject
     */
    protected function getOutputFile($is_dry_run, SplFileInfo $input, $filename)
    {
        $dir = $input->getPath() . DIRECTORY_SEPARATOR . 'converted';
        if (false === $is_dry_run && false === file_exists($dir)) {
            mkdir($dir);
        }
        $output = $dir . DIRECTORY_SEPARATOR . $filename;
        $output = new SplFileInfo($output);
        return $is_dry_run ? $output : $output->openFile('w');
    }

}
