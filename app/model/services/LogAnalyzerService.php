<?php

namespace Clevis;

use Clevis\LogAnalyzer\FileNotFoundException;
use DateTime;
use DibiConnection;
use Nette;
use Nette\Utils\Strings;


class LogAnalyzerService extends Nette\Object
{

	const MAX_LINE_LENGTH = 4096;

	/** @var DibiConnection */
	private $dibi;

	/** @var string */
	private $logDir;

	public function __construct(DibiConnection $dibi, $logDir)
	{
		$this->dibi = $dibi;
		$this->logDir = $logDir;
	}

	/**
	 * Returns logged errors.
	 *
	 * @param    DateTime
	 * @param    DateTime
	 * @param    bool   returns only erros that have not been fixed yet
	 * @return   array  level => errorId => DibiRow
	 */
	public function getErrors(DateTime $startDate = NULL, DateTime $endDate = NULL, $onlyActive = TRUE, $orderBy = NULL)
	{
		if (is_file($this->getErrorLogFile()))
		{
			$this->update();
		}

		$conds = array();
		if ($onlyActive) $conds[] = ['[status] = %s', 'active'];
		if ($startDate !== NULL) $conds[] = ['[last_time] >= %d', $startDate];
		if ($endDate !== NULL) $conds[] = ['[last_time] <= %d', $endDate];

		$result = $this->dibi->query('
			SELECT [error_id], [status], [file], [line], [message], [level], [count], [last_time], [issue_id]
			FROM [system_errors]
			WHERE %and', $conds, '
			ORDER BY ' . ($orderBy === NULL ? '[count]' : '[last_time]') . ' DESC
		');
		$errors = $result->fetchAssoc('level|error_id');

		if (count($errors))
		{
			$errorIds = [];
			foreach ($errors as $set) foreach (array_keys($set) as $errorId) $errorIds[] = $errorId;
			$redscreens = $this->getRedscreens($errorIds);
			foreach ($errors as $level => $set)
			{
				foreach (array_keys($set) as $errorId)
				{
					if (isset($redscreens[$errorId]))
					{
						$errors[$level][$errorId]['redscreens'] = $redscreens[$errorId];
					}
				}
			}
		}

		return $errors;
	}

	/**
	 * @param    array (# => errorId)
	 * @return   array (errorId => list of redscreens)
	 */
	public function getRedscreens($errorIds)
	{
		return $this->dibi->query('
			SELECT [error_id], [hash], [time]
			FROM [system_errors_redscreens]
			WHERE [error_id] IN %in', $errorIds, '
			ORDER BY [time] DESC
		')->fetchAssoc('error_id[]');
	}

	/**
	 * Returns list of URLs where given errors occured.
	 *
	 * @param    int
	 * @param    int
	 * @return   array             # => DibiRow (url, count)
	 */
	public function getUrls($errorId, $limit = NULL)
	{
		return $this->dibi->fetchAll('
			SELECT [url], [count]
			FROM [system_errors_urls]
			WHERE [error_id] = %i', $errorId, '
			ORDER BY [count] DESC
			%lmt', $limit
		);
	}

	/**
	 * @param    int
	 * @return   void
	 */
	public function markAsResolved($errorId)
	{
		$this->dibi->query('
			UPDATE [system_errors]
			SET [status] = %s', 'resolved', '
			WHERE [error_id] = %i', $errorId
		);
	}

	/**
	 * @param    int
	 * @return   void
	 */
	public function markAsReopened($errorId)
	{
		$this->dibi->query('
			UPDATE [system_errors]
			SET [status] = %s', 'active', '
			WHERE [error_id] = %i', $errorId
		);
	}

	/**
	 * Returns content of redscreen stored in given file.
	 *
	 * @param    string
	 * @return   string
	 * @throws   FileNotFoundException   pokud neexistuje soubor s redscreenem
	 */
	public function getRedscreenContent($fileName)
	{
		$path = $this->logDir . '/' . $fileName;
		$content = @file_get_contents($path);
		if ($content === FALSE)
		{
			throw new FileNotFoundException("File '$path' does not exist.");
		}

		return $content;
	}


	/**
	 * Naimportuje chyby z error logu do databáze.
	 *
	 * @return   void
	 */
	private function update()
	{
		set_time_limit(0);
		ignore_user_abort(TRUE);

		if (!flock($lock = fopen($this->logDir . '/lock-loganalyzer', 'w'), LOCK_EX))
		{
			return;
		}

		try
		{
			$logFile = $this->getErrorLogFile();
			$tempLogFile = $this->getTemporaryErrorLogFile();
			if (@rename($logFile, $tempLogFile))
			{
				$errors = $this->parseFile($tempLogFile);
				$this->saveErrors($errors);
				@unlink($tempLogFile);
			}
			flock($lock, LOCK_UN);
		}
		catch (\Exception $e)
		{
			flock($lock, LOCK_UN);
			throw $e;
		}
	}

	/**
	 * Parses error log and returns sorted and grouped errors.
	 *
	 * @param    string
	 * @return   array (errorHash => array)
	 */
	private function parseFile($file)
	{
		$errors = [];
		$handle = fopen($file, 'r');
		while (!feof($handle))
		{
			$line = fgets($handle, self::MAX_LINE_LENGTH);
			if ($line === FALSE) continue;
			$line = trim($line);
			if (empty($line)) continue;

			try
			{
				$error = $this->parseLine($line);
				$lastErrorLine = NULL;
			}
			catch (InvalidStateException $e)
			{
				if (!empty($lastErrorLine))
				{
					try
					{
						$error = $this->parseLine(trim($lastErrorLine) . ' ' . trim($line));
						$lastErrorLine = NULL;
					}
					catch (InvalidStateException $e)
					{
						$lastErrorLine = NULL;
						continue;
					}
				}
				else
				{
					$lastErrorLine = $line;
					continue;
				}
			}

			$hash = md5($error['severity'] . $error['message'] . $error['file'] . $error['line']);
			$urlHash = md5($error['url']);

			if (!isset($errors[$hash]))
			{
				$errors[$hash] = [
					'hash' => $hash,
					'severity' => $error['severity'],
					'count' => 1,
					'message' => $error['message'],
					'file' => $error['file'],
					'line' => $error['line'],
					'last_time' => $error['time'],
				];
			}
			else
			{
				$errors[$hash]['count']++;
				$errors[$hash]['last_time'] = $error['time'];
			}

			if (isset($error['redscreen']))
			{
				$errors[$hash]['redscreens'][] = $error['redscreen'];
			}

			if (!isset($errors[$hash]['urls'][$urlHash]))
			{
				$errors[$hash]['urls'][$urlHash] = [
					'count' => 1,
					'url' => $error['url'],
					'last_time' => $error['time'],
				];
			}
			else
			{
				$errors[$hash]['urls'][$urlHash]['count']++;
				$errors[$hash]['urls'][$urlHash]['last_time'] = $error['time'];
			}
		}

		fclose($handle);
		return $errors;
	}

	/**
	 * Parses a single line from error log.
	 *
	 * @param    string
	 * @return   array
	 * @throws   InvalidStateException
	 */
	private function parseLine($line)
	{
		$matches = Nette\Utils\Strings::match($line, '#^\[(?<date>.+?) (?<time>.+)\] (PHP )*?(?<severity>.+): (?<message>.+) in (?<file>[/\\\\a-z0-9_.:-]+):(?<line>\d+)(  @  (?<url>.+))?(  @@  (?<redscreen>.+))?$#Ui');
		if ($matches === NULL)
		{
			throw new InvalidStateException('Error log contains a line in unknown format.');
		}

		if (stripos($matches['severity'], 'exception') !== FALSE)
		{
			$matches['message'] = $matches['severity'] . ': ' . $matches['message'];
			$matches['severity'] = 'Fatal error';
		}

		$error = [
			'time' => new DateTime($matches['date'] . ' ' . str_replace('-', ':', $matches['time'])),
			'severity' => $matches['severity'],
			'message' => $matches['message'],
			'file' => $matches['file'],
			'line' => (int) $matches['line'],
			'url' => $matches['url'],
			'redscreen' => isset($matches['redscreen']) ? $matches['redscreen'] : NULL,
		];

		return $error;
	}

	/**
	 * Stores errors to database.
	 *
	 * @param    array (errorHash => array)
	 * @return   void
	 */
	private function saveErrors(array $errors)
	{
		foreach ($errors as $hash => $error)
		{
			$this->dibi->query('
				INSERT INTO [system_errors]', [
					'hash' => $hash,
					'file' => $error['file'],
					'line' => $error['line'],
					'message' => $error['message'],
					'level' => $error['severity'],
					'last_time' => $error['last_time'],
					'count' => $error['count']
				], '
				ON DUPLICATE KEY UPDATE
					[count] = [count] + VALUES([count]),
					[last_time] = VALUES([last_time])
			');

			$errorId = $this->dibi->getInsertId();
			$this->saveUrls($errorId, $error['urls']);
			if (isset($error['redscreens']) && $error['redscreens'])
			{
				$this->saveRedscreens($errorId, $error['redscreens']);
			}
		}
	}

	/**
	 * Stores URLs where given error happened to database.
	 *
	 * @param    int
	 * @param    array (urlHash => array)
	 * @return   void
	 */
	private function saveUrls($errorId, $urls)
	{
		foreach ($urls as $urlHash => $url)
		{
			$urls[$urlHash]['hash'] = $urlHash;
			$urls[$urlHash]['error_id'] = $errorId;
		}

		// It may not be possible to store URLs with a single query due to theirs amount,
		// therefore we split them into chunks.
		$chunks = array_chunk($urls, 50, TRUE);
		foreach ($chunks as $chunk)
		{
			$this->dibi->query('
				INSERT INTO [system_errors_urls]
					%ex', $chunk, '
				ON DUPLICATE KEY UPDATE
					[count] = [count] + VALUES([count]),
					[last_time] = VALUES([last_time])
			');
		}
	}

	/**
	 * Stores to given error information about available redscreens.
	 *
	 * @param    int
	 * @param    array (# => filename)
	 * @return   void
	 */
	private function saveRedscreens($errorId, array $redscreens)
	{
		$values = array();
		foreach ($redscreens as $redscreen)
		{
			$values[] = [
				'error_id' => $errorId,
				'hash' => $redscreen,
				'time%t' => @filectime($redscreen),
			];
		}

		$this->dibi->query('
			INSERT INTO [system_errors_redscreens]
				%ex', $values, '
			-- ignorování duplicitních záznamů (nelze použít INSERT IGNORE, protože to buď vloží vše nebo nic)
			ON DUPLICATE KEY UPDATE
				[hash] = [hash]
		');
	}

	/**
	 * @return   string
	 */
	private function getErrorLogFile()
	{
		return $this->logDir . '/error.log';
	}

	/**
	 * @return   string
	 */
	private function getTemporaryErrorLogFile()
	{
		return $this->logDir . '/error-' . Strings::random(4, 'a-z') . '.log.tmp';
	}

}
