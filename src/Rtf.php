<?php

namespace TextAtAnyCost;

/**
 * Class Rtf
 *
 * @author Alexey Rembish <alex@rembish.ru>
 * @copyright 2009, Alexey Rembish
 * @version 0.2
 * @package TextAtAnyCost
 */
class Rtf extends AbstractTextParser
{
    /**
     * @param $filename
     *
     * @return string|null
     */
    public static function rtf2text($filename)
    {
        return (new self($filename))->parse();
    }

    /**
     * @return string|null
     */
    public function parse()
    {
        $text = $this->data;

        # Speeding up via cutting binary data from large rtf's.
        if (strlen($text) > 1024 * 1024) {
            $text = preg_replace("#[\r\n]#", "", $text);
            $text = preg_replace("#[0-9a-f]{128,}#is", "", $text);
        }

        # For Unicode escaping
        $text = str_replace("\\'3f", "?", $text);
        $text = str_replace("\\'3F", "?", $text);

        $document = "";
        $stack = [];
        $j = -1;

        $fonts = [];

        for ($i = 0, $len = strlen($text); $i < $len; $i++) {
            $c = $text[$i];

            switch ($c) {
                case "\\":
                    $nc = $text[$i + 1];

                    if ($nc == '\\' && $this->rtfIsPlainText($stack[$j])) $document .= '\\';
                    elseif ($nc == '~' && $this->rtfIsPlainText($stack[$j])) $document .= ' ';
                    elseif ($nc == '_' && $this->rtfIsPlainText($stack[$j])) $document .= '-';
                    elseif ($nc == '*') $stack[$j]["*"] = true;
                    elseif ($nc == "'") {
                        $hex = substr($text, $i + 2, 2);
                        if ($this->rtfIsPlainText($stack[$j])) {
                            #echo $hex." ";
                            #dump($stack[$j], false);
                            #dump($fonts, false);
                            if (!empty($stack[$j]["mac"]) || @$fonts[$stack[$j]["f"]] == 77)
                                $document .= $this->fromMacRoman(hexdec($hex));
                            elseif (@$stack[$j]["ansicpg"] == "1251" || @$stack[$j]["lang"] == "1029")
                                $document .= chr(hexdec($hex));
                            else
                                $document .= "&#" . hexdec($hex) . ";";
                        }
                        #dump($stack[$j], false);
                        $i += 2;
                    } elseif ($nc >= 'a' && $nc <= 'z' || $nc >= 'A' && $nc <= 'Z') {
                        $word = "";
                        $param = null;

                        for ($k = $i + 1, $m = 0; $k < strlen($text); $k++, $m++) {
                            $nc = $text[$k];
                            if ($nc >= 'a' && $nc <= 'z' || $nc >= 'A' && $nc <= 'Z') {
                                if (empty($param))
                                    $word .= $nc;
                                else
                                    break;
                            } elseif ($nc >= '0' && $nc <= '9')
                                $param .= $nc;
                            elseif ($nc == '-') {
                                if (empty($param))
                                    $param .= $nc;
                                else
                                    break;
                            } else
                                break;
                        }
                        $i += $m - 1;

                        $toText = "";
                        switch (strtolower($word)) {
                            case "u":
                                $toText .= html_entity_decode("&#x" . sprintf("%04x", $param) . ";");
                                $ucDelta = !empty($stack[$j]["uc"]) ? @$stack[$j]["uc"] : 1;
                                /*for ($k = 1, $m = $i + 2; $k <= $ucDelta && $m < strlen($text); $k++, $m++) {
                                    $d = $text[$m];
                                    if ($d == '\\') {
                                        $dd = $text[$m + 1];
                                        if ($dd == "'")
                                            $m += 3;
                                        elseif($dd == '~' || $dd == '_')
                                            $m++;
                                    }
                                }
                                $i = $m - 2;*/
                                #$i += $m - 2;
                                if ($ucDelta > 0)
                                    $i += $ucDelta;
                                break;
                            case "par":
                            case "page":
                            case "column":
                            case "line":
                            case "lbr":
                                $toText .= "\n";
                                break;
                            case "emspace":
                            case "enspace":
                            case "qmspace":
                                $toText .= " ";
                                break;
                            case "tab":
                                $toText .= "\t";
                                break;
                            case "chdate":
                                $toText .= date("m.d.Y");
                                break;
                            case "chdpl":
                                $toText .= date("l, j F Y");
                                break;
                            case "chdpa":
                                $toText .= date("D, j M Y");
                                break;
                            case "chtime":
                                $toText .= date("H:i:s");
                                break;
                            case "emdash":
                                $toText .= html_entity_decode("&mdash;");
                                break;
                            case "endash":
                                $toText .= html_entity_decode("&ndash;");
                                break;
                            case "bullet":
                                $toText .= html_entity_decode("&#149;");
                                break;
                            case "lquote":
                                $toText .= html_entity_decode("&lsquo;");
                                break;
                            case "rquote":
                                $toText .= html_entity_decode("&rsquo;");
                                break;
                            case "ldblquote":
                                $toText .= html_entity_decode("&laquo;");
                                break;
                            case "rdblquote":
                                $toText .= html_entity_decode("&raquo;");
                                break;

                            # Skipping binary data...
                            case "bin":
                                $i += $param;
                                break;

                            case "fcharset":
                                $fonts[@$stack[$j]["f"]] = $param;
                                break;

                            default:
                                $stack[$j][strtolower($word)] = empty($param) ? true : $param;
                                break;
                        }
                        if ($this->rtfIsPlainText($stack[$j]))
                            $document .= $toText;
                    } else $document .= " ";

                    $i++;
                    break;
                case "{":
                    if ($j == -1)
                        $stack[++$j] = [];
                    else
                        array_push($stack, $stack[$j++]);
                    break;
                case "}":
                    array_pop($stack);
                    $j--;
                    break;
                case "\0":
                case "\r":
                case "\f":
                case "\b":
                case "\t":
                    break;
                case "\n":
                    $document .= " ";
                    break;
                default:
                    if ($this->rtfIsPlainText($stack[$j]))
                        $document .= $c;
                    break;
            }
        }

        $text = html_entity_decode(iconv("windows-1251", "utf-8", $document), ENT_QUOTES, "UTF-8");

        return empty($text) ? null : $text;
    }

    /**
     * Функция, которая проверяет, являются ли данные, с которыми мы сейчас работает
     * выводимым на экран текстом. Принцип её работы очень прост - в массиве failAt
     * записаны те ключевые слова для текущего состояния стека, которые показывают,
     * что перед нами что-то другое, а не текст - например, то могут быть описания
     * шрифтов или цветовой палитры. И так далее.
     *
     * @param string $s
     *
     * @return bool
     */
    protected function rtfIsPlainText($s)
    {
        $failAt = ["*", "fonttbl", "colortbl", "datastore", "themedata", "stylesheet", "info", "picw", "pich"];

        for ($i = 0; $i < count($failAt); $i++) {
            if (!empty($s[$failAt[$i]])) return false;
        }

        return true;
    }

    /**
     * Mac Roman charset for czech layout
     *
     * @param string $c
     *
     * @return string
     */
    protected function fromMacRoman($c)
    {
        $map = [
            0x83 => 0x00c9, 0x84 => 0x00d1, 0x87 => 0x00e1, 0x8e => 0x00e9, 0x92 => 0x00ed,
            0x96 => 0x00f1, 0x97 => 0x00f3, 0x9c => 0x00fa, 0xe7 => 0x00c1, 0xea => 0x00cd,
            0xee => 0x00d3, 0xf2 => 0x00da
        ];

        if (isset($map[$c])) {
            $c = "&#x" . sprintf("%04x", $map[$c]) . ";";
        }

        return $c;
    }
}
