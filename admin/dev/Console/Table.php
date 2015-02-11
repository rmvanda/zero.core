<?php
/**
 *
 * @depends on class DOMDocument
 *
 */
class Table
{
    /**
     * @property table - is @object DOMElement
     */
    public $table;
    /**
     * @property dom - is @object DOMDocument
     * @property thead - is @object DOMDocument
     *
     *
     */
    private $dom, $thead, $tbody, $tfoot, $th, $tr, $td;
    /**
     * @creates
     * @reads
     */
    public function __construct()
    {
        $this -> dom = new DOMDocument("1.0", "utf8-bin");
        $this -> table = $this -> dom -> createElement("table");
        $this -> thead = $this -> dom -> createElement("thead");
    }

    public function setHeaders($headers, $ctitle = false)
    {
        $this -> theadtr = $this -> dom -> createElement("tr");
        $this -> theadtr -> appendChild($this -> dom -> createElement("th", $ctitle ? : "#"));

        foreach ($headers as $header) {
            $this -> theadtr -> appendChild($this -> dom -> createElement("th", $header));
        }
        $this -> thead -> appendChild($this -> theadtr);
        $this -> table -> appendChild($this -> thead);
        return $this;
        //print_x($this->table);
        //   $this -> dom -> appendChild($this -> table);
        //  $this -> dom -> saveHTML();
        //echo htmlspecialchars($this -> display.-> saveHTML());
    }

    public function addRows($rows)

    {
        foreach ($rows as $row) {
            $this -> addRow($row);
        }
        return $this;
    }

    public function auto($magic)
    {
        foreach ($magic[0] as $key => $value) {
            $headers[] = $key;
        }

        $this -> setHeaders($headers);
        $this -> addRows($magic);

        return $this;
    }

    public function addRow($row)
    {
        $tr = $this -> dom -> createElement("tr");
        foreach ($row as $data) {
            $this -> addData($data, $tr);
        }
        $this -> table -> appendChild($tr);
    }

    public function addData($data, $tr)
    {
        $tr -> appendChild($this -> dom -> createElement("td", htmlspecialchars($data)));
    }

    public function display()
    {
        $this -> output();
    }

    private function output()
    {
        $this -> dom -> appendChild($this -> table);
        echo $this -> dom -> saveHTML();

    }

    public function magic($object)
    {

        if (is_object($object)) {
            $this -> tableObject();
        }
        if (is_array($object)) {
            if (isset($object) && is_array($object[0])) {
                $this -> tableNumArray();
            } else {
                //   $this->tableN
            }
        }
    }

}
