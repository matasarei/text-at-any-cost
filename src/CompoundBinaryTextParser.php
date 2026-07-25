<?php

namespace TextAtAnyCost;

/**
 * Class CompoundBinaryTextParser
 *
 * @author Alexey Rembish <alex@rembish.ru>
 * @copyright 2009, Alexey Rembish
 * @version 0.2
 * @package TextAtAnyCost
 */
abstract class CompoundBinaryTextParser extends AbstractTextParser
{
	protected $data = "";

	protected $sectorShift = 9;
	protected $miniSectorShift = 6;
	protected $miniSectorCutoff = 4096;

	protected $fatChains = [];
	protected $fatEntries = [];

	protected $miniFATChains = [];
	protected $miniFAT = "";

	private $version = 3;
	private $isLittleEndian = true;

	private $cDir = 0;
	private $fDir = 0;

	private $cFAT = 0;

	private $cMiniFAT = 0;
	private $fMiniFAT = 0;

	private $DIFAT = [];
	private $cDIFAT = 0;
	private $fDIFAT = 0;

	const ENDOFCHAIN = 0xFFFFFFFE;
	const FREESECT   = 0xFFFFFFFF;

	/**
	 * @return bool
	 */
	public function parse()
	{
		$abSig = strtoupper(bin2hex(substr($this->data, 0, 8)));

		if ($abSig != "D0CF11E0A1B11AE1" && $abSig != "0E11FC0DD0CF11E0") {
			return false;
		}

		$this->readHeader();
		$this->readDIFAT();
		$this->readFATChains();
		$this->readMiniFATChains();
		$this->readDirectoryStructure();

		$reStreamID = $this->getStreamIdByName("Root Entry");

		if ($reStreamID === false) {
			return false;
		}

		$this->miniFAT = $this->getStreamById($reStreamID, true);

		unset($this->DIFAT);

		return true;
	}

	/**
	 * Функция, которая находит (если находит) номер потока (stream'а) в структуре "директории"
	 * по его имени. В противном случае - false.
	 *
	 * @param string $name
	 * @param int $from
	 *
	 * @return bool|int
	 */
	public function getStreamIdByName($name, $from = 0)
	{
		for ($i = $from; $i < count($this->fatEntries); $i++) {
			if ($this->fatEntries[$i]["name"] == $name) {
				return $i;
			}
		}

		return false;
	}

	/**
	 * Функция получает на вход номер потока ($id) и, в качестве исключения для корневого
	 * вхождения, второй параметр. Возвращает бинарное содержимое данного потока.
	 *
	 * @param int $id
	 * @param bool $isRoot
	 *
	 * @return string
	 */
	public function getStreamById($id, $isRoot = false)
	{
		$entry = $this->fatEntries[$id];
		$from = $entry["start"];
		$size = $entry["size"];


		$stream = "";
		if ($size < $this->miniSectorCutoff && !$isRoot) {
			$ssize = 1 << $this->miniSectorShift;

			do {
				$start = $from << $this->miniSectorShift;
				$stream .= substr($this->miniFAT, $start, $ssize);
				$from = isset($this->miniFATChains[$from]) ? $this->miniFATChains[$from] : self::ENDOFCHAIN;
			} while ($from != self::ENDOFCHAIN);
		} else {
			$ssize = 1 << $this->sectorShift;
			
			do {
				$start = ($from + 1) << $this->sectorShift;
				$stream .= substr($this->data, $start, $ssize);
				$from = isset($this->fatChains[$from]) ? $this->fatChains[$from] : self::ENDOFCHAIN;
			} while ($from != self::ENDOFCHAIN);
		}
		return substr($stream, 0, $size);
	}

	/**
	 * Функция читает нужные и важные данные из заголовка файла.
	 */
	private function readHeader()
	{
		$uByteOrder = strtoupper(bin2hex(substr($this->data, 0x1C, 2)));
		$this->isLittleEndian = $uByteOrder == "FEFF";

		$this->version = $this->getShort(0x1A);

		$this->sectorShift = $this->getShort(0x1E);
		$this->miniSectorShift = $this->getShort(0x20);
		$this->miniSectorCutoff = $this->getLong(0x38);

		if ($this->version == 4)
			$this->cDir = $this->getLong(0x28);
		$this->fDir = $this->getLong(0x30);

		$this->cFAT = $this->getLong(0x2C);

		$this->cMiniFAT = $this->getLong(0x40);
		$this->fMiniFAT = $this->getLong(0x3C);

		$this->cDIFAT = $this->getLong(0x48);
		$this->fDIFAT = $this->getLong(0x44);
	}

	/**
	 * Итак, DIFAT. DIFAT показывает в каких секторах файла лежат
	 * описания цепочек FAT-секторов. Без этих цепочек мы не сможем
	 * прочитать содержимое потоков в сильно "фрагментированных" файлах.
	 */
	private function readDIFAT()
	{
		$this->DIFAT = [];
		for ($i = 0; $i < 109; $i++) {
			$this->DIFAT[$i] = $this->getLong(0x4C + $i * 4);
		}

		if ($this->fDIFAT != self::ENDOFCHAIN) {
			$size = 1 << $this->sectorShift;
			$from = $this->fDIFAT;
			$j = 0;

			do {
				$start = ($from + 1) << $this->sectorShift;
				for ($i = 0; $i < ($size - 4); $i += 4)
					$this->DIFAT[] = $this->getLong($start + $i);
				$from = $this->getLong($start + $i);
			} while ($from != self::ENDOFCHAIN && ++$j < $this->cDIFAT);
		}

		while ($this->DIFAT[count($this->DIFAT) - 1] == self::FREESECT) {
			array_pop($this->DIFAT);
		}
	}

	/**
	 * Так, DIFAT мы прочитали - теперь нужно ссылки на цепочки FAT-секторов
	 * превратить в реальные цепочки. Поэтому побегаем по файлу дальше.
	 */
	private function readFATChains()
	{
		$size = 1 << $this->sectorShift;
		$this->fatChains = [];

		for ($i = 0; $i < count($this->DIFAT); $i++) {
			$from = ($this->DIFAT[$i] + 1) << $this->sectorShift;
			for ($j = 0; $j < $size; $j += 4) {
				$this->fatChains[] = $this->getLong($from + $j);
			}
		}
	}

	/**
	 * FAT-цепочки мы прочитали, теперь нужно прочитать MiniFAT-цепочки абсолютно также.
	 */
	private function readMiniFATChains()
	{
		$size = 1 << $this->sectorShift;
		$this->miniFATChains = [];

		$from = $this->fMiniFAT;
		while ($from != self::ENDOFCHAIN) {
			$start = ($from + 1) << $this->sectorShift;
			for ($i = 0; $i < $size; $i += 4) {
				$this->miniFATChains[] = $this->getLong($start + $i);
			}
			$from = isset($this->fatChains[$from]) ? $this->fatChains[$from] : self::ENDOFCHAIN;
		}
	}

	/**
	 * Самая важная функция, которая читает структуру "файлов" данного файла (уж простите
	 * за каламбур). В эту структуру записаны все объекты ФС данного файла.
	 */
	private function readDirectoryStructure()
	{
		$from = $this->fDir;
		$size = 1 << $this->sectorShift;
		$this->fatEntries = [];
		do {
			$start = ($from + 1) << $this->sectorShift;
			for ($i = 0; $i < $size; $i += 128) {
				$entry = substr($this->data, $start + $i, 128);
				$this->fatEntries[] = [
					"name" => $this->utf16ToAnsi(substr($entry, 0, $this->getShort(0x40, $entry))),
					"type" => ord($entry[0x42]),
					"color" => ord($entry[0x43]),
					"left" => $this->getLong(0x44, $entry),
					"right" => $this->getLong(0x48, $entry),
					"child" => $this->getLong(0x4C, $entry),
					"start" => $this->getLong(0x74, $entry),
					"size" => $this->getSomeBytes($entry, 0x78, 8),
				];
			}

			$from = isset($this->fatChains[$from]) ? $this->fatChains[$from] : self::ENDOFCHAIN;
		} while ($from != self::ENDOFCHAIN);

		while($this->fatEntries[count($this->fatEntries) - 1]["type"] == 0) {
			array_pop($this->fatEntries);
		}
	}

	/**
	 * Вспомогательная функция для получения адекватного имени текущего вхождения в ФС.
	 * Замечу, что имена записаны в Unicode.
	 *
	 * @param string $in
	 *
	 * @return string
	 */
	private function utf16ToAnsi($in)
	{
		$out = "";

		for ($i = 0; $i < strlen($in); $i += 2) {
			$out .= chr($this->getShort($i, $in));
		}

		return trim($out);
	}

	/**
	 * Функция преобразования из Unicode в UTF8, а то как-то не айс.
	 *
	 * @param string $in
	 * @param bool $check
	 *
	 * @return string
	 */
	protected function unicodeToUtf8($in, $check = false)
	{
		$out = "";

		if ($check && strpos($in, chr(0)) !== 1) {
			while (($i = strpos($in, chr(0x13))) !== false) {
				$j = strpos($in, chr(0x15), $i + 1);

				if ($j === false) {
					break;
				}

				$in = substr_replace($in, "", $i, $j - $i);
			}

			for ($i = 0; $i < strlen($in); $i++) {
				if (ord($in[$i]) >= 32) {}
				elseif ($in[$i] == ' ' || $in[$i] == '\n') {}
				else {
					$in = substr_replace($in, "", $i, 1);
				}
			}

			$in = str_replace(chr(0), "", $in);

			return $in;
		} elseif ($check) {
			while (($i = strpos($in, chr(0x13).chr(0))) !== false) {
				$j = strpos($in, chr(0x15).chr(0), $i + 1);

				if ($j === false) {
					break;
				}

				$in = substr_replace($in, "", $i, $j - $i);
			}
			$in = str_replace(chr(0).chr(0), "", $in);
		}

		$skip = false;

		for ($i = 0; $i < strlen($in); $i += 2) {
			$cd = substr($in, $i, 2);

			if ($skip) {
				if (ord($cd[1]) == 0x15 || ord($cd[0]) == 0x15) {
					$skip = false;
				}

				continue;
			}

			if (ord($cd[1]) == 0) {
				if (ord($cd[0]) >= 32) {
					$out .= $cd[0];
				} elseif ($cd[0] == ' ' || $cd[0] == '\n') {
					$out .= $cd[0];
				} elseif (ord($cd[0]) == 0x13) {
					$skip = true;
				} else {
					continue;
					switch (ord($cd[0])) {
						case 0x0D: case 0x07: $out .= "\n"; break;
						case 0x08: case 0x01: $out .= ""; break;
						case 0x13: $out .= "HYPER13"; break;
						case 0x14: $out .= "HYPER14"; break;
						case 0x15: $out .= "HYPER15"; break;
						default: $out .= " "; break;
					}
				}
			} else { 
				if (ord($cd[1]) == 0x13) {
					echo "@";
					$skip = true;

					continue;
				}

				$out .= "&#x".sprintf("%04x", $this->getShort(0, $cd)).";";
			}
		}

		return $out;
	}

	/**
	 * Вспомогательная функция для чтения некоторого количества байт из строки
	 * с учётом порядка байтов и преобразования значение в число.
	 *
	 * @param string|null $data
	 * @param int $from
	 * @param int $count
	 *
	 * @return float|int
	 */
	protected function getSomeBytes($data, $from, $count)
	{
		if ($data === null) {
			$data = $this->data;
		}

		$string = substr($data, $from, $count);
		if ($this->isLittleEndian) {
			$string = strrev($string);
		}

		return hexdec(bin2hex($string));
	}

	/**
	 * Читаем слово из переменной (по умолчанию из this->data)
	 *
	 * @param int $from
	 * @param string|null $data
	 *
	 * @return float|int
	 */
	protected function getShort($from, $data = null)
	{
		return $this->getSomeBytes($data, $from, 2);
	}

	/**
	 * Читаем двойное слово из переменной (по умолчанию из this->data)
	 *
	 * @param
	 * @param string|null $data
	 *
	 * @return float|int
	 */
	protected function getLong($from, $data = null)
	{
		return $this->getSomeBytes($data, $from, 4);
	}
}
