<?php

namespace TextAtAnyCost;

/**
 * Class Ppt
 *
 * @author Alexey Rembish <alex@rembish.ru>
 * @copyright 2009, Alexey Rembish
 * @version 0.3
 * @package TextAtAnyCost
 */
class Ppt extends CompoundBinaryTextParser
{
    /**
     * @param string $filename
     *
     * @return null|string
     */
    public static function ppt2text($filename)
    {
        return (new self($filename))->parse();
    }

    /**
     * @return string|null
     */
	public function parse()
    {
		parent::parse();

		$cuStreamID = $this->getStreamIdByName("Current User");
		if ($cuStreamID === false) { return false; }

		$cuStream = $this->getStreamById($cuStreamID);
		if ($this->getLong(12, $cuStream) == 0xF3D1C4DF) { return false; }
		$offsetToCurrentEdit = $this->getLong(16, $cuStream);

		$ppdStreamID = $this->getStreamIdByName("PowerPoint Document");
		if ($ppdStreamID === false) { return false; }
		$ppdStream = $this->getStreamById($ppdStreamID);

		$offsetLastEdit = $offsetToCurrentEdit;
		$persistDirEntry = [];
		$live = null;
		$offsetPersistDirectory = [];
		do {
			$userEditAtom = $this->getRecord($ppdStream, $offsetLastEdit, 0x0FF5);
			$live = &$userEditAtom;
			array_unshift($offsetPersistDirectory, $this->getLong(12, $userEditAtom));
			$offsetLastEdit = $this->getLong(8, $userEditAtom);
		} while ($offsetLastEdit != 0x00000000);

		for ($j = 0; $j < count($offsetPersistDirectory); $j++) {
			$rgPersistDirEntry = $this->getRecord($ppdStream, $offsetPersistDirectory[$j], 0x1772);
			if ($rgPersistDirEntry === false) { return false; }

			for ($k = 0; $k < strlen($rgPersistDirEntry); ) {
				$persist = $this->getLong($k, $rgPersistDirEntry);
				$persistId = $persist & 0x000FFFFF;
				$cPersist = (($persist & 0xFFF00000) >> 20) & 0x00000FFF;
				$k += 4;

				for ($i = 0; $i < $cPersist; $i++) {
					$offset = $this->getLong($k + $i * 4, $rgPersistDirEntry);
					$persistDirEntry[$persistId + $i] = $this->getLong($k + $i * 4, $rgPersistDirEntry);
				}
				$k += $cPersist * 4;
			}
		}

		$docPersistIdRef = $this->getLong(16, $live);
		$documentContainer = $this->getRecord($ppdStream, $persistDirEntry[$docPersistIdRef], 0x03E8);

		$offset = 40 + 8;
		$exObjList = $this->getRecord($documentContainer, $offset, 0x0409);
		if ($exObjList) $offset += strlen($exObjList) + 8;
		$documentTextInfo = $this->getRecord($documentContainer, $offset, 0x03F2);
		$offset += strlen($documentTextInfo) + 8;
		$soundCollection = $this->getRecord($documentContainer, $offset, 0x07E4);
		if ($soundCollection) $offset += strlen($soundCollection) + 8;
		$drawingGroup = $this->getRecord($documentContainer, $offset, 0x040B);
		$offset += strlen($drawingGroup) + 8;
		$masterList = $this->getRecord($documentContainer, $offset, 0x0FF0);
		$offset += strlen($masterList) + 8;
		$docInfoList = $this->getRecord($documentContainer, $offset, 0x07D0);
		if ($docInfoList) $offset += strlen($docInfoList) + 8;
		$slideHF = $this->getRecord($documentContainer, $offset, 0x0FD9);
		if ($slideHF) $offset += strlen($slideHF) + 8;
		$notesHF = $this->getRecord($documentContainer, $offset, 0x0FD9);
		if ($notesHF) $offset += strlen($notesHF) + 8;

		unset($exObjList, $documentTextInfo, $soundCollection, $drawingGroup, $masterList, $docInfoList, $slideHF, $notesHF);

		$slideList = $this->getRecord($documentContainer, $offset, 0x0FF0);
		$out = "";
		for ($i = 0; $i < strlen($slideList); ) {
			$block = $this->getRecord($slideList, $i);
			switch($this->getRecordType($slideList, $i)) {
				case 0x03F3: # RT_SlidePersistAtom
					$pid = $this->getLong(0, $block);
					$slide = $this->getRecord($ppdStream, @$persistDirEntry[$pid], 0x03EE);

					$offset = 32;
					$slideShowSlideInfoAtom = $this->getRecord($slide, $offset, 0x03F9);
					if ($slideShowSlideInfoAtom) $offset += strlen($slideShowSlideInfoAtom) + 8;
					$perSlideHFContainer = $this->getRecord($slide, $offset, 0x0FD9);
					if ($perSlideHFContainer) $offset += strlen($perSlideHFContainer) + 8;
					$rtSlideSyncInfo12 = $this->getRecord($slide, $offset, 0x3714);
					if ($rtSlideSyncInfo12) $offset += strlen($rtSlideSyncInfo12) + 8;

					$drawing = $this->getRecord($slide, $offset, 0x040C);
					$from = 0;
					while(preg_match("#(\xA8|\xA0)\x0F#", $drawing, $pocket, PREG_OFFSET_CAPTURE, $from)) {
						$pocket = @$pocket[1];
						if (substr($drawing, $pocket[1] - 2, 2) == "\x00\x00") {
							if (ord($pocket[0]) == 0xA8)
								$out .= htmlspecialchars($this->getRecord($drawing, $pocket[1] - 2, 0x0FA8))." ";
							else
								$out .= $this->unicode_to_utf8($this->getRecord($drawing, $pocket[1] - 2, 0x0FA0))." ";
						}
						$from = $pocket[1] + 2;
					}
				break;
				case 0x0FA0: # RT_TextCharsAtom
					$out .= $this->unicode_to_utf8($block)." ";
				break;
				case 0x0FA8: # RT_TextBytesAtom
					$out .= htmlspecialchars($block)." ";
				break;
				# some other skipped
			}

			$i += strlen($block) + 8;
		}

		$text = html_entity_decode(iconv("windows-1251", "utf-8", $out), ENT_QUOTES, "UTF-8");

		return empty($text) ? null : $text;
	}

    /**
     * Дополнительная функция, определяющая длину текущей внутренней структуры.
     * Принимает на вход поток, из которого получать данные, смещение, по которому
     * их читать и тип структуры, по которому проверять читаем ли мы правильную информацию.
     *
     * @param string $stream
     * @param int $offset
     * @param string|null $recType
     *
     * @return bool|float|int
     */
	private function getRecordLength($stream, $offset, $recType = null)
    {
		$rh = substr($stream, $offset, 8);

		if (!is_null($recType) && $recType != $this->getShort(2, $rh)) {
            return false;
        }

		return $this->getLong(4, $rh);
	}

    /**
     * Получение типа текущей структуры в соответствии с "прейскурантом" от MS.
     *
     * @param string $stream
     * @param int $offset
     *
     * @return float|int
     */
	private function getRecordType($stream, $offset)
    {
		$rh = substr($stream, $offset, 8);

		return $this->getShort(2, $rh);
	}

    /**
     * Получение записи из потока по смещению, возможно заданного типа. Внимание, заголовок назад не передаётся.
     *
     * @param string $stream
     * @param int $offset
     * @param string|null $recType
     *
     * @return bool|false|string
     */
	private function getRecord($stream, $offset, $recType = null)
    {
		$length = $this->getRecordLength($stream, $offset, $recType);

		if ($length === false) {
            return false;
        }

		return substr($stream, $offset + 8, $length);
	}
}
