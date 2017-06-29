<?php
require_once 'config.php';


function runHledger($params, $input, &$output)
{
	$descriptorspec = array(
	   0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
	   1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
	   2 => array("file", "/tmp/error-output.txt", "a") // stderr is a file to write to
	);

	$cwd = '/tmp';
	$env = array();

	$process = proc_open($GLOBALS['PATH_TO_HLEDGER_BINARY'].' '.$params, $descriptorspec, $pipes, $cwd, $env);

	if (is_resource($process)) {
	    // $pipes now looks like this:
	    // 0 => writeable handle connected to child stdin
	    // 1 => readable handle connected to child stdout
	    // Any error output will be appended to /tmp/error-output.txt

	    fwrite($pipes[0], $input);
	    fclose($pipes[0]);

	    $output = stream_get_contents($pipes[1]);
	    fclose($pipes[1]);

	    // It is important that you close any pipes before calling
	    // proc_close in order to avoid a deadlock
	    $return_value = proc_close($process);

	    return $return_value;
	}
	return NULL;
}

function transpose($array) {
    $return = array();
    foreach($array as $key => $value) {
        foreach ($value as $key2 => $value2) {
            $return[$key2][$key] = $value2;
        }
    }
    //print_r($return);
    return $return;
}

function testLedgerColumn($col)
{
	if ($col != "txnidx" && $col != "short account" && $col != "indent")
	{
		return true;
	}
	else
	{
		return false;
	}
}

function generateLedgerTable($ledgerCsv, $transpose = false, $issueReference = null, $dataReference = null, $includeUserColumn = false)
{
	$output = "";
	$csvOutput = "";
	$lines = explode("\n", trim($ledgerCsv));
	$ledger = array_map('str_getcsv', $lines);
	if ($transpose)
	{
		$ledgerTemp = transpose($ledger);
		$ledger = array();
		for ($i = 0; $i < count($ledgerTemp); $i++)
		{
			if (testLedgerColumn($ledgerTemp[$i][0]))
			{
				$ledger[] = $ledgerTemp[$i];
			}
		}
	}
	$output .= "<table class=\"niceborder\" width=\"90%\" cellpadding=\"8\" border=\"1\">";
	$txnidx = NULL;
	for ($i = 0; $i < count($ledger); $i++)
	{
		$output .= "<tr>";
		for ($j = 0; $j < count($ledger[$i]); $j++)
		{
			if ($i == 0 && $ledger[0][$j] == "txnidx")
			{
				$txnidx = $j;
			}
			if (testLedgerColumn($ledger[0][$j]))
			{
				if ($i == 0)
				{
					$output .= "<th>";
				}
				else
				{
					if ($ledger[0][$j] != "account" && $ledger[0][$j] != "date" && $ledger[0][$j] != "description")
					{
						$output .= "<td align=\"right\">";
					}
					else
					{
						$output .= "<td>";
					}
				}
				if ($ledger[0][$j] == "date")
				{
					if ($i == 0)
					{
						if ($includeUserColumn)
						{
							$output .= "User</th><th>";
							$csvOutput .= "\"User\",";
						}
						$output .= "Date";
						$csvOutput .= "\"Date\"";
					}
					else
					{
						if ($includeUserColumn)
						{
							$output .= $dataReference[(int)($ledger[$i][$txnidx]) - 1]->a."</td><td>";
							$csvOutput .= "\"".$dataReference[(int)($ledger[$i][$txnidx]) - 1]->a."\",";
						}
						$output .= htmlentities($ledger[$i][$j]);
						$csvOutput .= "\"".htmlentities($ledger[$i][$j])."\"";
					}
				}
				else if ($ledger[0][$j] == "account")
				{
					if ($i == 0)
					{
						$output .= "Task";
						$csvOutput .= "\"Task\"";
					}
					else
					{
						$csvOutput .= "\"";
						if ($issueReference !== NULL && $txnidx !== NULL)
						{
							$output .= $issueReference[(int)($ledger[$i][$txnidx]) - 1].": <a href=\"https://".$GLOBALS['JIRA_DOMAIN']."/browse/".$issueReference[(int)($ledger[$i][$txnidx]) - 1]."\">";
							$csvOutput .= $issueReference[(int)($ledger[$i][$txnidx]) - 1].": ";
						}
						if ($ledger[$i][$j][0] == "(")
						{
							$output .= htmlentities(substr($ledger[$i][$j], 1, strlen($ledger[$i][$j])-2));
							$csvOutput .= htmlentities(substr($ledger[$i][$j], 1, strlen($ledger[$i][$j])-2))."\"";
						}
						else
						{
							$output .= htmlentities($ledger[$i][$j]);
							$csvOutput .= htmlentities($ledger[$i][$j])."\"";
						}
						if ($issueReference !== NULL && $txnidx !== NULL)
						{
							$output .= "</a>";
						}
					}
				}
				else
				{
					if ($i == 0)
					{
						$output .= htmlentities(ucwords($ledger[$i][$j]));
						$csvOutput .= "\"".htmlentities(ucwords($ledger[$i][$j]))."\"";
						if ($ledger[0][$j] != "description")
						{
							//$csvOutput .= ",\"".htmlentities(ucwords($ledger[$i][$j]))." Units\"";
						}
					}
					else
					{
						if ($ledger[0][$j] == "description")
						{
							$output .= "<span title=\"".str_replace('\n', '&#013;', $dataReference[(int)($ledger[$i][$txnidx]) - 1]->c)."\">".htmlentities($ledger[$i][$j])."</span>";
						}
						else
						{
							$output .= htmlentities($ledger[$i][$j]);
						}
						if ($ledger[0][$j] == "description")
						{
							$csvOutput .= "\"".htmlentities($ledger[$i][$j])."\"";
						}
						else
						{
							$value = $ledger[$i][$j];
							//$units = "";
							if (is_numeric($ledger[$i][$j][0]) && $ledger[$i][$j][strlen($ledger[$i][$j])-1] == 'h')
							{
								$value = substr($ledger[$i][$j], 0, strlen($ledger[$i][$j])-1);
								//$units = "h";
							}
							//$csvOutput .= "\"".htmlentities($value)."\",\"".$units."\"";
							$csvOutput .= "\"".htmlentities($value)."\"";
						}
					}
				}
				if ($i == 0)
				{
					$output .= "</th>";
				}
				else
				{
					$output .= "</td>";
				}
				if ($j != count($ledger[$i]) - 1)
				{
					$csvOutput .= ",";
				}
			}
		}
		$output .= "</tr>";
		$csvOutput .= "\r\n";
	}
	$output .= "</table>";
	return (object) array("output" => $output, "csv" => $csvOutput);
}
?>
