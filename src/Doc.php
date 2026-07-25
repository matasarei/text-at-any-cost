<?php

namespace TextAtAnyCost;

/**
	 * Parse function extends the parent one and returns
	 * text from the given file. Returns false on failure.
	 *
	 * @return string|null
	 */
	public function parse()
	{
		parent::parse();

		$wdStreamID = $this->getStreamIdByName("WordDocument");

		if ($wdStreamID === false) {
			return false;
		}

		$wdStream = $this->getStreamById($wdStreamID);

		$bytes = $this->getShort(0x000A, $wdStream);
		$fWhichTblStm = ($bytes & 0x0200) == 0x0200;

		$fcClx = $this->getLong(0x01A2, $wdStream);
		$lcbClx = $this->getLong(0x01A6, $wdStream);

		$ccpText = $this->getLong(0x004C, $wdStream);
		$ccpFtn = $this->getLong(0x0050, $wdStream);
		$ccpHdd = $this->getLong(0x0054, $wdStream);
		$ccpMcr = $this->getLong(0x0058, $wdStream);
		$ccpAtn = $this->getLong(0x005C, $wdStream);
		$ccpEdn = $this->getLong(0x0060, $wdStream);
		$ccpTxbx = $this->getLong(0x0064, $wdStream);
		$ccpHdrTxbx = $this->getLong(0x0068, $wdStream);

		$lastCP = $ccpFtn + $ccpHdd + $ccpMcr + $ccpAtn + $ccpEdn + $ccpTxbx + $ccpHdrTxbx;
		$lastCP += ($lastCP != 0) + $ccpText;

		$tStreamID = $this->getStreamIdByName(intval($fWhichTblStm)."Table");

		if ($tStreamID === false) {
			return false;
		}

		$tStream = $this->getStreamById($tStreamID);
		$clx = substr($tStream, $fcClx, $lcbClx);

		$lcbPieceTable = 0;
		$pieceTable = "";


		$from = 0;
		while (($i = strpos($clx, chr(0x02), $from)) !== false) {
			$lcbPieceTable = $this->getLong($i + 1, $clx);
			$pieceTable = substr($clx, $i + 5);

			if (strlen($pieceTable) != $lcbPieceTable) {
				$from = $i + 1;
				continue;
			}
			break;
		}

		$cp = [];
		$i = 0;

		while (($cp[] = $this->getLong($i, $pieceTable)) != $lastCP) {
			$i += 4;
		}

		$pcd = str_split(substr($pieceTable, $i + 4), 8);

		$text = "";
		for ($i = 0; $i < count($pcd); $i++) {
			$fcValue = $this->getLong(2, $pcd[$i]);
			$isANSI = ($fcValue & 0x40000000) == 0x40000000;
			$fc = $fcValue & 0x3FFFFFFF;

			$lcb = $cp[$i + 1] - $cp[$i];
			if (!$isANSI) {
				$lcb *= 2;
			} else { 
				$fc /= 2;
			}

			$part = substr($wdStream, $fc, $lcb);
			if (!$isANSI) {
				$part = $this->unicodeToUtf8($part);
			}

			$text .= $part;
		}

		$text = preg_replace("/HYPER13 *(INCLUDEPICTURE|HTMLCONTROL)(.*)HYPER15/iU", "", $text);
		$text = preg_replace("/HYPER13(.*)HYPER14(.*)HYPER15/iU", "$2", $text);

		return empty($text) ? null : $text;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function unicodeToUtf8($in, $check = false)
	{
		$out = "";
		for ($i = 0; $i < strlen($in); $i += 2) {
			$cd = substr($in, $i, 2);

			if (ord($cd[1]) == 0) {
				if (ord($cd[0]) >= 32) {
					$out .= $cd[0];
				}

				switch (ord($cd[0])) {
					case 0x0D: case 0x07: $out .= "\n"; break;
					case 0x08: case 0x01: $out .= ""; break;
					case 0x13: $out .= "HYPER13"; break;
					case 0x14: $out .= "HYPER14"; break;
					case 0x15: $out .= "HYPER15"; break;
				}
			} else { 
				$out .= html_entity_decode("&#x" . sprintf("%04x", $this->getShort(0, $cd)) . ";");
			}
		}

		return $out;
	}
}
