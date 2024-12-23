<?php
define("XMEX_CONTAINER_MODE", in_array(strtolower(getenv("XMEX_CONTAINER_MODE")), ["on", "true", "1"]));
define("XMEX_DEBUG_MODE", in_array(strtolower(getenv("XMEX_DEBUG_MODE")), ["on", "true", "1"]));
define("XMEX_STORAGE_DIRECTORY", getenv("XMEX_STORAGE_DIRECTORY") ?: "./data");
define("XMEX_STORAGE_QUANTITY", getenv("XMEX_STORAGE_QUANTITY") ?: 65535);
define("XMEX_STORAGE_SPACE", getenv("XMEX_STORAGE_SPACE") ?: 256 *1024);
define("XMEX_STORAGE_EXPIRATION", getenv("XMEX_STORAGE_EXPIRATION") ?: 15 *60);
define("XMEX_STORAGE_REVISION_TYPE", (XMEX_DEBUG_MODE || strcasecmp(getenv("XMEX_STORAGE_REVISION_TYPE"), "serial") === 0) ? "serial" : "timestamp");
define("XMEX_URI_XPATH_DELIMITER", getenv("XMEX_URI_XPATH_DELIMITER") ?: "!");
class Storage {
    const DIRECTORY = XMEX_STORAGE_DIRECTORY;
    const QUANTITY = XMEX_STORAGE_QUANTITY;
    const SPACE = XMEX_STORAGE_SPACE;
    const EXPIRATION = XMEX_STORAGE_EXPIRATION;
    const DELIMITER = XMEX_URI_XPATH_DELIMITER;
    const DEBUG_MODE = XMEX_DEBUG_MODE;
    const CONTAINER_MODE = XMEX_CONTAINER_MODE;
    const REVISION_TYPE = XMEX_STORAGE_REVISION_TYPE;
    const XML_DOCUMENT_VERSION  = "1.0";
    const XML_DOCUMENT_ENCODING = "UTF-8";
    const CORS = [
        "Access-Control-Allow-Origin" => "*",
        "Access-Control-Allow-Credentials" => "true",
        "Access-Control-Max-Age" => "86400",
        "Access-Control-Expose-Headers" => "*"
    ];
    private $storage;
    private $root;
    private $store;
    private $share;
    private $xml;
    private $xpath;
    private $options;
    private $revision;
    private $serial;
    private $unique;
    const PATTERN_BASE64 = "/^\?(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/";
    const PATTERN_HEX = "/^\?([A-Fa-f0-9]{2})+$/";
    const PATTERN_NON_NUMERICAL = "/^.*\D/";
    const PATTERN_HTTP_REQUEST = "/^([A-Z]+)\s+(.+?)\s*(?:\s+(HTTP\/\d+(?:\.\d+)*))?$/i";
    const PATTERN_HTTP_REQUEST_URI = "/^(.*?)" . Storage::DELIMITER . "(.*)$/i";
    const PATTERN_HEADER_STORAGE = "/^(\w(?:[-\w]{0,62}\w)?)(?:\s+(\w{1,64}))?$/";
    const PATTERN_XPATH_ATTRIBUTE = "/((?:^\/+)|(?:^.*?))\/{0,}(?<=\/)(?:@|attribute::)(\w+)$/i";
    const PATTERN_XPATH_PSEUDO = "/^(.*?)(?:::(before|after|first|last)){0,1}$/i";
    const PATTERN_XPATH_FUNCTION = "/^[\(\s]*[^\/\.\s\(].*$/";
    const CONTENT_TYPE_TEXT  = "text/plain";
    const CONTENT_TYPE_XPATH = "text/xpath";
    const CONTENT_TYPE_HTML  = "text/html";
    const CONTENT_TYPE_XML   = "application/xml";
    const CONTENT_TYPE_XSLT  = "application/xslt+xml";
    const CONTENT_TYPE_JSON  = "application/json";
    const STORAGE_SHARE_NONE      = 0;
    const STORAGE_SHARE_EXCLUSIVE = 1;
    const STORAGE_SHARE_INITIAL   = 2;
    function __construct($storage = null, $root = null, $xpath = null) {
        if (!empty($storage))
            $root = $root ?: "data";
        else $root = null;
        $store = null;
        if (!empty($storage)) {
            $store = $storage . "[" . $root . "]";
            $store = preg_replace("/(^|[^a-z])([a-z])/", "$1'$2", $store);
            $store = preg_replace("/([a-z])([^a-z]|$)/", "$1'$2", $store);
            $store = Storage::DIRECTORY . "/" . strtolower($store);
            if (Storage::DEBUG_MODE)
                $store .= ".xml";
        }
        $this->storage  = $storage;
        $this->root     = $root;
        $this->store    = $store;
        $this->xpath    = $xpath;
        $this->serial   = 0;
        $this->unique   = null;
        $this->revision = null;
    }
    private static function cleanUp() {
        if (!is_dir(Storage::DIRECTORY))
            return;
        $marker = Storage::DIRECTORY . "/cleanup";
        if (file_exists($marker)
                && time() -filemtime($marker) < 60)
            return;
        touch($marker);
        if ($handle = opendir(Storage::DIRECTORY)) {
            $expiration = time() -Storage::EXPIRATION;
            while (($entry = readdir($handle)) !== false) {
                if (in_array($entry, [".", "..", "cleanup"]))
                    continue;
                $entry = Storage::DIRECTORY . "/$entry";
                if (file_exists($entry)
                        && filemtime($entry) < $expiration)
                    @unlink($entry);
            }
            closedir($handle);
        }
    }
    static function share($storage, $xpath, $options = Storage::STORAGE_SHARE_NONE) {
        $root = preg_replace(Storage::PATTERN_HEADER_STORAGE, "$2", $storage);
        $storage = preg_replace(Storage::PATTERN_HEADER_STORAGE, "$1", $storage);
        if (!file_exists(Storage::DIRECTORY))
            mkdir(Storage::DIRECTORY, 0755, true);
        $storage = new Storage($storage, $root, $xpath);
        $expiration = time() -Storage::EXPIRATION;
        if (file_exists($storage->store)
                && filemtime($storage->store) < $expiration)
            @unlink($storage->store);
        $initial = ($options & Storage::STORAGE_SHARE_INITIAL) == Storage::STORAGE_SHARE_INITIAL;
        if (!$initial && !$storage->exists())
            $storage->quit(404, "Not Found");
        $initial = $initial && (!file_exists($storage->store) || filesize($storage->store) <= 0);
        $storage->share = fopen($storage->store, "c+");
        $exclusive = ($options & Storage::STORAGE_SHARE_EXCLUSIVE) == Storage::STORAGE_SHARE_EXCLUSIVE;
        flock($storage->share, $initial || $exclusive ? LOCK_EX : LOCK_SH);
        touch($storage->store);
        if (strcasecmp(Storage::REVISION_TYPE, "serial") !== 0) {
            $storage->unique = round(microtime(true) *1000);
            while ($storage->unique == round(microtime(true) *1000))
                usleep(1);
            $storage->unique = base_convert($storage->unique, 10, 36);
            $storage->unique = strtoupper($storage->unique);
        } else $storage->unique = 1;
        if ($initial) {
            $iterator = new FilesystemIterator(Storage::DIRECTORY, FilesystemIterator::SKIP_DOTS);
            if (iterator_count($iterator) >= Storage::QUANTITY)
                $storage->quit(507, "Insufficient Storage");
            fwrite($storage->share,
                "<?xml version=\"" . Storage::XML_DOCUMENT_VERSION . "\" encoding=\"" . Storage::XML_DOCUMENT_ENCODING . "\"?>"
                . "<{$storage->root} ___rev=\"{$storage->unique}\" ___uid=\"{$storage->getSerial()}\"/>");
            rewind($storage->share);
            if (strcasecmp(Storage::REVISION_TYPE, "serial") === 0)
                $storage->unique = 0;
        }
        fseek($storage->share, 0, SEEK_END);
        $size = ftell($storage->share);
        rewind($storage->share);
        $storage->xml = new DOMDocument(Storage::XML_DOCUMENT_VERSION, Storage::XML_DOCUMENT_ENCODING);
        $storage->xml->preserveWhiteSpace = false;
        $storage->xml->formatOutput = Storage::DEBUG_MODE;
        $storage->xml->loadXML(fread($storage->share, $size));
        $storage->revision = $storage->xml->documentElement->getAttribute("___rev");
        if (strcasecmp(Storage::REVISION_TYPE, "serial") === 0) {
            if (preg_match(Storage::PATTERN_NON_NUMERICAL, $storage->revision))
                $storage->quit(503, "Resource revision conflict");
            $storage->unique += $storage->revision;
        }
        return $storage;
    }
    private function exists() {
        return file_exists($this->store)
                && filesize($this->store) > 0;
    }
    function materialize() {
        if ($this->share == null)
            return;
        if ($this->revision == $this->xml->documentElement->getAttribute("___rev")
                && $this->revision != $this->unique)
            return;
        $output = $this->xml->saveXML();
        if (strlen($output) > Storage::SPACE)
            $this->quit(413, "Content Too Large");
        ftruncate($this->share, 0);
        rewind($this->share);
        fwrite($this->share, $output);
        if (Storage::DEBUG_MODE) {
            $unique = sprintf("%03d", $this->unique);
            $target = preg_replace("/(\.\w+$)/", "___$unique$1", $this->store);
            file_put_contents($target, $output);
        }
    }
    function close() {
        if ($this->share == null)
            return;
        flock($this->share, LOCK_UN);
        fclose($this->share);
        $this->share = null;
        $this->xml = null;
    }
    private function getSerial() {
        return $this->unique . ":" . ++$this->serial;
    }
    private function getSize() {
        if ($this->xml !== null)
            return strlen($this->xml->saveXML());
        if ($this->share !== null)
            return filesize($this->share);
        if ($this->store !== null
                && file_exists($this->store))
            return filesize($this->store);
        return 0;
    }
    private static function updateNodeRevision($node, $revision) {
        while ($node && $node->nodeType === XML_ELEMENT_NODE) {
            $node->setAttribute("___rev", $revision);
            $node = $node->parentNode;
        }
    }
    private static function isMediaTypeAccepted($media, $strict = false) {
        if (!isset($_SERVER["HTTP_ACCEPT"]))
            return !$strict;
        $accept = strtolower($_SERVER["HTTP_ACCEPT"]);
        $accept = array_map('trim', explode(',', $accept));
        return in_array(strtolower($media), $accept, true);
    }
    function doConnect() {
        Storage::cleanUp();
        if ($this->xpath !== null
                && strlen($this->xpath))
            $this->quit(400, "Bad Request", ["Message" => "Unexpected XPath"]);
        $response = [201, "Created"];
        if ($this->revision != $this->unique)
            $response = [304, "Not Modified"];
        $this->materialize();
        $this->quit($response[0], $response[1], ["Allow" => "CONNECT, OPTIONS, GET, POST, PUT, PATCH, DELETE"]);
    }
    function doOptions() {
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $allow = "CONNECT, OPTIONS, GET, POST, PUT, PATCH, DELETE";
        if (preg_match(Storage::PATTERN_XPATH_FUNCTION, $this->xpath)) {
            (new DOMXpath($this->xml))->evaluate($this->xpath);
            if (Storage::fetchLastXmlErrorMessage()) {
                $message = "Invalid XPath function (" . Storage::fetchLastXmlErrorMessage() . ")";
                $this->quit(400, "Bad Request", ["Message" => $message]);
            }
            $allow = "CONNECT, OPTIONS, GET, POST";
        } elseif ($this->xpath !== null
                && strlen($this->xpath) > 0) {
            $targets = (new DOMXpath($this->xml))->query($this->xpath);
            if (Storage::fetchLastXmlErrorMessage()) {
                $message = "Invalid XPath axis (" . Storage::fetchLastXmlErrorMessage() . ")";
                $this->quit(400, "Bad Request", ["Message" => $message]);
            }
            if (empty($targets)
                    || $targets->length <= 0)
                $allow = "CONNECT, OPTIONS, PUT";
        }
        $this->quit(204, "No Content", ["Allow" => $allow]);
    }
    function doGet() {
        if ($this->xpath === null
                || strlen($this->xpath) <= 0)
            $this->quit(400, "Bad Request", ["Message" => "Invalid XPath"]);
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        if (preg_match(Storage::PATTERN_XPATH_FUNCTION, $this->xpath))
            $result = (new DOMXpath($this->xml))->evaluate($this->xpath);
        else $result = (new DOMXpath($this->xml))->query($this->xpath);
        if (Storage::fetchLastXmlErrorMessage()) {
            $message = "Invalid XPath";
            if (preg_match(Storage::PATTERN_XPATH_FUNCTION, $this->xpath))
                $message = "Invalid XPath function";
            $message .= " (" . Storage::fetchLastXmlErrorMessage() . ")";
            $this->quit(400, "Bad Request", ["Message" => $message]);
        } else if (!preg_match(Storage::PATTERN_XPATH_FUNCTION, $this->xpath)
                &&  (empty($result)
                        || $result->length <= 0)) {
            $this->quit(204, "No Content");
        } else if ($result instanceof DOMNodeList) {
            if ($result->length == 1) {
                if ($result[0] instanceof DOMDocument)
                    $result = [$result[0]->documentElement];
                if ($result[0] instanceof DOMAttr) {
                    $result = $result[0]->value;
                } else {
                    $xml = new DOMDocument(Storage::XML_DOCUMENT_VERSION, Storage::XML_DOCUMENT_ENCODING);
                    $xml->preserveWhiteSpace = false;
                    $xml->formatOutput = Storage::DEBUG_MODE;
                    $xml->appendChild($xml->importNode($result[0], true));
                    $result = $xml;
                }
            } else if ($result->length > 0) {
                $xml = new DOMDocument(Storage::XML_DOCUMENT_VERSION, Storage::XML_DOCUMENT_ENCODING);
                $xml->preserveWhiteSpace = false;
                $xml->formatOutput = Storage::DEBUG_MODE;
                $collection = $xml->createElement("collection");
                $xml->importNode($collection, true);
                foreach ($result as $entry) {
                    if ($entry instanceof DOMAttr)
                        $entry = $xml->createElement($entry->name, $entry->value);
                    $collection->appendChild($xml->importNode($entry, true));
                }
                $xml->appendChild($collection);
                $result = $xml;
            } else $result = "";
        } else if (is_bool($result)) {
            $result = $result ? "true" : "false";
        }
        $this->quit(200, "Success", null, $result);
    }
    function doPost() {
        if (!isset($_SERVER["CONTENT_TYPE"])
                || strcasecmp($_SERVER["CONTENT_TYPE"], Storage::CONTENT_TYPE_XSLT) !== 0)
            $this->quit(415, "Unsupported Media Type");
        if (preg_match(Storage::PATTERN_XPATH_FUNCTION, $this->xpath)) {
            $message = "Invalid XPath (Functions are not supported)";
            $this->quit(400, "Bad Request", ["Message" => $message]);
        }
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $style = new DOMDocument(Storage::XML_DOCUMENT_VERSION, Storage::XML_DOCUMENT_ENCODING);
        $style->preserveWhiteSpace = false;
        $style->formatOutput = Storage::DEBUG_MODE;
        $input = file_get_contents("php://input");
        if (empty($input)
                || !$style->loadXML($input)
                || Storage::fetchLastXmlErrorMessage()) {
            $message = "Invalid XSLT stylesheet";
            if (Storage::fetchLastXmlErrorMessage())
                $message .= " (" . Storage::fetchLastXmlErrorMessage() . ")";
            $this->quit(422, "Unprocessable Content", ["Message" => $message]);
        }
        $processor = new XSLTProcessor();
        $processor->importStyleSheet($style);
        if (Storage::fetchLastXmlErrorMessage()) {
             $message = "Invalid XSLT stylesheet (" . Storage::fetchLastXmlErrorMessage() . ")";
             $this->quit(422, "Unprocessable Content", ["Message" => $message]);
        }
        $xml = $this->xml;
        if ($this->xpath !== null
                && strlen($this->xpath) > 0) {
            $xml = new DOMDocument(Storage::XML_DOCUMENT_VERSION, Storage::XML_DOCUMENT_ENCODING);
            $targets = (new DOMXpath($this->xml))->query($this->xpath);
            if (Storage::fetchLastXmlErrorMessage()) {
                $message = "Invalid XPath axis (" . Storage::fetchLastXmlErrorMessage() . ")";
                $this->quit(400, "Bad Request", ["Message" => $message]);
            }
            if (empty($targets)
                    || $targets->length <= 0)
                $this->quit(204, "No Content");
            if ($targets->length == 1) {
                $target = $targets[0];
                if ($target instanceof DOMAttr)
                    $target = $xml->createElement($target->name, $target->value);
                $xml->appendChild($xml->importNode($target, true));
            } else {
                $collection = $xml->createElement("collection");
                $xml->importNode($collection, true);
                foreach ($targets as $target) {
                    if ($target instanceof DOMAttr)
                        $target = $xml->createElement($target->name, $target->value);
                    $collection->appendChild($xml->importNode($target, true));
                }
                $xml->appendChild($collection);
            }
        }
        $output = $processor->transformToXML($xml);
        if ($output === false
                || Storage::fetchLastXmlErrorMessage()) {
            $message = "Invalid XSLT stylesheet";
            if (Storage::fetchLastXmlErrorMessage())
                $message .= " (" . Storage::fetchLastXmlErrorMessage() . ")";
            $this->quit(422, "Unprocessable Content", ["Message" => $message]);
        }
        $method = trim(strtolower((new DOMXpath($style))->evaluate("normalize-space(//*[local-name()='output']/@method)") ?? ""));
        if (!in_array($method, ["", "xml", "html", "text"]))
            $this->quit(415, "Unsupported Media Type");
        if (empty($output)) {
            if (!in_array($method, ["", "xml"]))
                $this->quit(204, "No Content");
            $output = new DOMDocument(Storage::XML_DOCUMENT_VERSION, Storage::XML_DOCUMENT_ENCODING);
        }
        $header = ["Content-Type" => Storage::CONTENT_TYPE_XML];
        if (strcmp($method, "text") === 0)
            $header = ["Content-Type" => Storage::CONTENT_TYPE_TEXT];
        else if (strcmp($method, "html") === 0)
            $header = ["Content-Type" => Storage::CONTENT_TYPE_HTML];
        else if (Storage::isMediaTypeAccepted(Storage::CONTENT_TYPE_JSON, true))
            $output = simplexml_load_string($output);
        $encoding = trim((new DOMXpath($style))->evaluate("normalize-space(//*[local-name()='output']/@encoding)") ?? "");
        $this->quit(200, "Success", $header, $output, $encoding);
    }
    function doPut() {
        if ($this->xpath === null
                || strlen($this->xpath) <= 0)
            $this->quit(400, "Bad Request", ["Message" => "Invalid XPath"]);
        if (strlen(file_get_contents("php://input")) > Storage::SPACE)
            $this->quit(413, "Content Too Large");
        if (!isset($_SERVER["CONTENT_TYPE"]))
            $this->quit(415, "Unsupported Media Type");
        if (preg_match(Storage::PATTERN_XPATH_FUNCTION, $this->xpath)) {
            $message = "Invalid XPath (Functions are not supported)";
            $this->quit(400, "Bad Request", ["Message" => $message]);
        }
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        if (preg_match(Storage::PATTERN_XPATH_ATTRIBUTE, $this->xpath, $matches, PREG_UNMATCHED_AS_NULL)) {
            if (!in_array(strtolower($_SERVER["CONTENT_TYPE"]), [Storage::CONTENT_TYPE_TEXT, Storage::CONTENT_TYPE_XPATH]))
                $this->quit(415, "Unsupported Media Type");
            $input = file_get_contents("php://input");
            if (strcasecmp($_SERVER["CONTENT_TYPE"], Storage::CONTENT_TYPE_XPATH) === 0) {
                if (!preg_match(Storage::PATTERN_XPATH_FUNCTION, $input)) {
                    $message = "Invalid XPath (Axes are not supported)";
                    $this->quit(422, "Unprocessable Content", ["Message" => $message]);
                }
                $input = (new DOMXpath($this->xml))->evaluate($input);
                if ($input === false
                        || Storage::fetchLastXmlErrorMessage()) {
                    $message = "Invalid XPath function";
                    if (Storage::fetchLastXmlErrorMessage())
                        $message .= " (" . Storage::fetchLastXmlErrorMessage() . ")";
                    $this->quit(422, "Unprocessable Content", ["Message" => $message]);
                }
            }
            $xpath = $matches[1];
            $attribute = $matches[2];
            $targets = (new DOMXpath($this->xml))->query($xpath);
            if (Storage::fetchLastXmlErrorMessage()) {
                $message = "Invalid XPath axis (" . Storage::fetchLastXmlErrorMessage() . ")";
                $this->quit(400, "Bad Request", ["Message" => $message]);
            }
            if (empty($targets)
                    || $targets->length <= 0)
                $this->quit(304, "Not Modified");
            if (!in_array($attribute, ["___rev", "___uid"])) {
                foreach ($targets as $target) {
                    if ($target->nodeType != XML_ELEMENT_NODE)
                        continue;
                    $target->setAttribute($attribute, $input);
                    $this->serial++;
                    Storage::updateNodeRevision($target, $this->unique);
                }
            }
            if ($this->serial <= 0)
                $this->quit(304, "Not Modified");
            $this->materialize();
            $this->quit(204, "No Content");
        }
        if (!preg_match(Storage::PATTERN_XPATH_PSEUDO, $this->xpath, $matches, PREG_UNMATCHED_AS_NULL))
            $this->quit(400, "Bad Request", ["Message" => "Invalid XPath axis"]);
        $xpath = $matches[1];
        $pseudo = $matches[2];
        $media = strtolower($_SERVER["CONTENT_TYPE"]);
        if (in_array($media, [Storage::CONTENT_TYPE_TEXT, Storage::CONTENT_TYPE_XPATH])) {
            if (!empty($pseudo))
                $this->quit(415, "Unsupported Media Type");
            $input = file_get_contents("php://input");
            if (strcasecmp($media, Storage::CONTENT_TYPE_XPATH) === 0) {
                if (!preg_match(Storage::PATTERN_XPATH_FUNCTION, $input)) {
                    $message = "Invalid XPath (Axes are not supported)";
                    $this->quit(422, "Unprocessable Content", ["Message" => $message]);
                }
                $input = (new DOMXpath($this->xml))->evaluate($input);
                if ($input === false
                        || Storage::fetchLastXmlErrorMessage()) {
                    $message = "Invalid XPath function";
                    if (Storage::fetchLastXmlErrorMessage())
                        $message .= " (" . Storage::fetchLastXmlErrorMessage() . ")";
                    $this->quit(422, "Unprocessable Content", ["Message" => $message]);
                }
            }
            $targets = (new DOMXpath($this->xml))->query($xpath);
            if (Storage::fetchLastXmlErrorMessage()) {
                $message = "Invalid XPath axis (" . Storage::fetchLastXmlErrorMessage() . ")";
                $this->quit(400, "Bad Request", ["Message" => $message]);
            }
            if (empty($targets)
                    || $targets->length <= 0)
                $this->quit(304, "Not Modified");
            foreach ($targets as $target) {
                if ($target->nodeType != XML_ELEMENT_NODE)
                    continue;
                $replace = $target->cloneNode(false);
                $replace->appendChild($this->xml->createTextNode($input));
                $target->parentNode->replaceChild($this->xml->importNode($replace, true), $target);
                $this->serial++;
                Storage::updateNodeRevision($replace, $this->unique);
            }
            if ($this->serial <= 0)
                $this->quit(304, "Not Modified");
            $this->materialize();
            $this->quit(204, "No Content");
        }
        if (strcasecmp($media, Storage::CONTENT_TYPE_XML) !== 0)
            $this->quit(415, "Unsupported Media Type");
        $input = file_get_contents("php://input");
        $input = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><data>$input</data>";
        $xml = new DOMDocument(Storage::XML_DOCUMENT_VERSION, Storage::XML_DOCUMENT_ENCODING);
        $xml->preserveWhiteSpace = false;
        $xml->formatOutput = Storage::DEBUG_MODE;
        if (!$xml->loadXML($input)
                || Storage::fetchLastXmlErrorMessage()) {
            $message = "Invalid XML document";
            if (Storage::fetchLastXmlErrorMessage())
                $message .= " (" . Storage::fetchLastXmlErrorMessage() . ")";
            $this->quit(422, "Unprocessable Content", ["Message" => $message]);
        }
        $nodes = (new DOMXpath($xml))->query("//*[@___rev|@___uid]");
        foreach ($nodes as $node) {
            $node->removeAttribute("___rev");
            $node->removeAttribute("___uid");
        }
        if ($xml->documentElement->hasChildNodes()) {
            $targets = (new DOMXpath($this->xml))->query($xpath);
            if (Storage::fetchLastXmlErrorMessage()) {
                $message = "Invalid XPath axis (" . Storage::fetchLastXmlErrorMessage() . ")";
                $this->quit(400, "Bad Request", ["Message" => $message]);
            }
            if (empty($targets)
                    || $targets->length <= 0)
                $this->quit(304, "Not Modified");
            foreach ($targets as $target) {
                if ($target->nodeType != XML_ELEMENT_NODE)
                    continue;
                if (!empty($pseudo))
                    $pseudo = strtolower($pseudo);
                if (empty($pseudo)) {
                    $replace = $target->cloneNode(false);
                    foreach ($xml->documentElement->childNodes as $insert)
                        $replace->appendChild($this->xml->importNode($insert->cloneNode(true), true));
                    $target->parentNode->replaceChild($this->xml->importNode($replace, true), $target);
                } else if (strcmp($pseudo, "before") === 0) {
                    if ($target->parentNode->nodeType == XML_ELEMENT_NODE)
                        foreach ($xml->documentElement->childNodes as $insert)
                            $target->parentNode->insertBefore($this->xml->importNode($insert, true), $target);
                } else if (strcmp($pseudo, "after") === 0) {
                    if ($target->parentNode->nodeType == XML_ELEMENT_NODE) {
                        $nodes = [];
                        foreach($xml->documentElement->childNodes as $node)
                            array_unshift($nodes, $node);
                        foreach ($nodes as $insert)
                            if ($target->nextSibling)
                                $target->parentNode->insertBefore($this->xml->importNode($insert, true), $target->nextSibling);
                            else $target->parentNode->appendChild($this->xml->importNode($insert, true));
                    }
                } else if (strcmp($pseudo, "first") === 0) {
                    $inserts = $xml->documentElement->childNodes;
                    for ($index = $inserts->length -1; $index >= 0; $index--)
                        $target->insertBefore($this->xml->importNode($inserts->item($index), true), $target->firstChild);
                } else if (strcmp($pseudo, "last") === 0) {
                    foreach ($xml->documentElement->childNodes as $insert)
                        $target->appendChild($this->xml->importNode($insert, true));
                } else $this->quit(400, "Bad Request", ["Message" => "Invalid XPath axis (Unsupported pseudo syntax found)"]);
            }
        }
        $nodes = (new DOMXpath($this->xml))->query("//*[not(@___uid)]");
        foreach ($nodes as $node) {
            $node->setAttribute("___uid", $this->getSerial());
            Storage::updateNodeRevision($node, $this->unique);
        }
        if ($this->serial <= 0)
            $this->quit(304, "Not Modified");
        $this->materialize();
        $this->quit(204, "No Content");
    }
    function doPatch() {
        if ($this->xpath === null
                || strlen($this->xpath) <= 0)
            $this->quit(400, "Bad Request", ["Message" => "Invalid XPath"]);
        if (strlen(file_get_contents("php://input")) > Storage::SPACE)
            $this->quit(413, "Content Too Large");
        if (!isset($_SERVER["CONTENT_TYPE"]))
            $this->quit(415, "Unsupported Media Type");
        if (preg_match(Storage::PATTERN_XPATH_FUNCTION, $this->xpath)) {
            $message = "Invalid XPath (Functions are not supported)";
            $this->quit(400, "Bad Request", ["Message" => $message]);
        }
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $targets = (new DOMXpath($this->xml))->query($this->xpath);
        if (Storage::fetchLastXmlErrorMessage()) {
            $message = "Invalid XPath axis (" . Storage::fetchLastXmlErrorMessage() . ")";
            $this->quit(400, "Bad Request", ["Message" => $message]);
        }
        if (empty($targets)
                || $targets->length <= 0)
            $this->quit(304, "Not Modified");
        $this->doPut();
    }
    function doDelete() {
        if ($this->xpath === null
                || strlen($this->xpath) <= 0)
            $this->quit(400, "Bad Request", ["Message" => "Invalid XPath"]);
        if (preg_match(Storage::PATTERN_XPATH_FUNCTION, $this->xpath)) {
            $message = "Invalid XPath (Functions are not supported)";
            $this->quit(400, "Bad Request", ["Message" => $message]);
        }
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $pseudo = false;
        if (preg_match(Storage::PATTERN_XPATH_ATTRIBUTE, $this->xpath)) {
            $xpath = $this->xpath;
        } else {
            if (!preg_match(Storage::PATTERN_XPATH_PSEUDO, $this->xpath, $matches, PREG_UNMATCHED_AS_NULL))
                $this->quit(400, "Bad Request", ["Message" => "Invalid XPath axis"]);
            $xpath = $matches[1];
            $pseudo = $matches[2];
        }
        $targets = (new DOMXpath($this->xml))->query($xpath);
        if (Storage::fetchLastXmlErrorMessage()) {
            $message = "Invalid XPath axis (" . Storage::fetchLastXmlErrorMessage() . ")";
            $this->quit(400, "Bad Request", ["Message" => $message]);
        }
        if (empty($targets)
                || $targets->length <= 0)
            $this->quit(304, "Not Modified");
        if ($pseudo) {
            if (strcasecmp($pseudo, "before") === 0) {
                $childs = [];
                foreach ($targets as $target) {
                    if (!$target->previousSibling)
                        continue;
                    for ($previous = $target->previousSibling; $previous; $previous = $previous->previousSibling)
                        $childs[] = $previous;
                }
                $targets = $childs;
            } else if (strcasecmp($pseudo, "after") === 0) {
                $childs = [];
                foreach ($targets as $target) {
                    if (!$target->nextSibling)
                        continue;
                    for ($next = $target->nextSibling; $next; $next = $next->nextSibling)
                        $childs[] = $next;
                }
                $targets = $childs;
            } else if (strcasecmp($pseudo, "first") === 0) {
                $childs = [];
                foreach ($targets as $target)
                    if ($target->firstChild)
                        $childs[] = $target->firstChild;
                $targets = $childs;
            } else if (strcasecmp($pseudo, "last") === 0) {
                $childs = [];
                foreach ($targets as $target)
                    if ($target->lastChild)
                        $childs[] = $target->lastChild;
                $targets = $childs;
            } else $this->quit(400, "Bad Request", ["Message" => "Invalid XPath axis (Unsupported pseudo syntax found)"]);
        }
        foreach ($targets as $target) {
            if ($target->nodeType === XML_ATTRIBUTE_NODE) {
                if (!$target->parentNode
                        || $target->parentNode->nodeType !== XML_ELEMENT_NODE
                        || in_array($target->name, ["___rev", "___uid"]))
                    continue;
                $parent = $target->parentNode;
                $parent->removeAttribute($target->name);
                $this->serial++;
                Storage::updateNodeRevision($parent, $this->unique);
            } else if ($target->nodeType !== XML_DOCUMENT_NODE) {
                if (!$target->parentNode
                        || !in_array($target->parentNode->nodeType, [XML_ELEMENT_NODE, XML_DOCUMENT_NODE]))
                    continue;
                $parent = $target->parentNode;
                $parent->removeChild($target);
                $this->serial++;
                if ($parent->nodeType === XML_DOCUMENT_NODE) {
                    $this->serial--;
                    $target = $this->xml->createElement($this->root);
                    $target = $this->xml->appendChild($target);
                    Storage::updateNodeRevision($target, $this->unique);
                    $target->setAttribute("___uid", $this->getSerial());
                } else Storage::updateNodeRevision($parent, $this->unique);
            }
        }
        if ($this->serial <= 0)
            $this->quit(304, "Not Modified");
        $this->materialize();
        $this->quit(204, "No Content");
    }
    function quit($status, $message, $headers = null, $data = null, $encoding = null) {
        if (headers_sent()) {
            $this->close();
            exit;
        }
        header(trim("HTTP/1.0 $status $message"));
        $filter = ["X-Powered-By", "Content-Type", "Content-Length"];
        foreach ($filter as $header) {
            header("$header:");
            header_remove($header);
        }
        foreach (Storage::CORS as $key => $value)
            header("$key: $value");
        if ($_SERVER["REQUEST_METHOD"] == "OPTIONS") {
            if (isset($_SERVER["HTTP_ACCESS_CONTROL_REQUEST_METHOD"]))
                header("Access-Control-Allow-Methods: CONNECT, OPTIONS, GET, POST, PUT, PATCH, DELETE");
            if (isset($_SERVER["HTTP_ACCESS_CONTROL_REQUEST_HEADERS"]))
                header("Access-Control-Allow-Headers: {$_SERVER["HTTP_ACCESS_CONTROL_REQUEST_HEADERS"]}");
        }
        if (!$headers)
            $headers = [];
        if ((($status >= 200 && $status < 300) || $status == 304)
                && $this->storage
                && $this->xml) {
            $expiration = new DateTime();
            $expiration->add(new DateInterval("PT" . Storage::EXPIRATION . "S"));
            $expiration = $expiration->format("D, d M Y H:i:s T");
            $headers = array_merge($headers, [
                "Storage" => $this->storage,
                "Storage-Revision" => $this->xml->documentElement->getAttribute("___rev") . "/" . $this->serial,
                "Storage-Space" => Storage::SPACE . "/" . $this->getSize() . " bytes",
                "Storage-Last-Modified" => date("D, d M Y H:i:s T"),
                "Storage-Expiration" => $expiration,
                "Storage-Expiration-Time" => (Storage::EXPIRATION *1000) . " ms"
            ]);
            if ($status != 200)
                $data = null;
            if (is_string($data)
                    && strlen($data) <= 0)
                $data = null;
            if ($data !== null) {
                if (Storage::isMediaTypeAccepted(Storage::CONTENT_TYPE_JSON, true)) {
                    $headers["Content-Type"] = Storage::CONTENT_TYPE_JSON;
                    if ($data instanceof DOMDocument
                            || $data instanceof SimpleXMLElement)
                        $data = simplexml_import_dom($data);
                    $data = json_encode($data, JSON_UNESCAPED_SLASHES);
                } else {
                    if (!array_key_exists("Content-Type", $headers))
                        $headers["Content-Type"] = ($data instanceof DOMDocument
                                || $data instanceof SimpleXMLElement)
                            ? Storage::CONTENT_TYPE_XML
                            : Storage::CONTENT_TYPE_TEXT;
                    if ($data instanceof DOMDocument
                            || $data instanceof SimpleXMLElement)
                        $data = $data->saveXML();
                }
                $headers["Content-Length"] = strlen($data);
                if (trim($encoding ?? "") !== "")
                    $headers["Content-Type"] .= "; charset=$encoding";
            }
        } else $data = null;
        foreach ($headers as $key => $value) {
            $value = trim(preg_replace("/[\r\n]+/", " ", $value));
            if (strlen(trim($value)) > 0)
                header("$key: $value");
            else header_remove($key);
        }
        if (Storage::DEBUG_MODE) {
            $request = isset($_SERVER["REQUEST"]) ? $_SERVER["REQUEST"] ?: ""
                : join(" ", [$_SERVER["REQUEST_METHOD"],
                    $_SERVER["REQUEST_URI"], $_SERVER["SERVER_PROTOCOL"]]);
            header("Trace-Request-Hash: " . hash("md5", $request));
            $header = join("\t", [
                $_SERVER["HTTP_STORAGE"] ?? "null",
                $_SERVER["CONTENT_TYPE"] ?? "null",
                $_SERVER["CONTENT_LENGTH"] ?? "null"]);
            header("Trace-Request-Header-Hash: " . hash("md5", $header));
            header("Trace-Request-Data-Hash: " . hash("md5", @file_get_contents("php://input") ?: ""));
            header("Trace-Response-Hash: " . hash("md5", $status . " " . $message));
            $header = join("\t", [
                $headers["Storage"] ?? "null",
                $headers["Storage-Revision"] ?? "null",
                $headers["Storage-Space"] ?? "null",
                $headers["Error"] ?? "null",
                $headers["Message"] ?? "null",
                $headers["Content-Type"] ?? "null",
                $headers["Content-Length"] ?? "null"]);
            header("Trace-Response-Header-Hash: " . hash("md5", $header));
            header("Trace-Response-Data-Hash: " . hash("md5", strval($data)));
            $header = $this->storage && $this->xml
                ? $this->xml?->saveXML() ?: "" : "";
            header("Trace-Storage-Hash: " . hash("md5", $header));
        }
        header("Execution-Time: " . round((microtime(true) -$_SERVER["REQUEST_TIME_FLOAT"]) *1000) . " ms");
        if ($data !== null
                && strlen($data) > 0)
            print($data);
        $this->close();
        exit;
    }
    private static function fetchLastXmlErrorMessage() {
        if (empty(libxml_get_errors()))
            return false;
        $message = libxml_get_errors();
        $message = end($message)->message;
        $message = preg_replace("/[\r\n]+/", " ", $message);
        $message = preg_replace("/\.+$/", " ", $message);
        return trim($message);
    }
    static function onError($error, $message, $file, $line) {
        $filter = "XSLTProcessor::transformToXml()";
        if (str_starts_with($message, $filter)) {
            $message = "Invalid XSLT stylesheet";
            if (Storage::fetchLastXmlErrorMessage())
                $message .= " (" . Storage::fetchLastXmlErrorMessage() . ")";
            (new Storage)->quit(422, "Unprocessable Content", ["Message" => $message]);
        }
        $unique = round(microtime(true) *1000);
        $unique = base_convert($unique, 10, 36);
        $unique = "#" . strtoupper($unique);
        $message = "$message" . PHP_EOL . "\tat $file $line";
        if (!is_numeric($error))
            $message = "$error: " . $message;
        $time = time();
        $output = Storage::CONTAINER_MODE ? "/dev/stdout" : date("Ymd", $time) . ".log";
        file_put_contents($output, date("Y-m-d H:i:s", $time) . " $unique $message" . PHP_EOL, FILE_APPEND | LOCK_EX);
        (new Storage)->quit(500, "Internal Server Error", ["Error" => $unique]);
    }
    static function onException($exception) {
        Storage::onError(get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine());
    }
}
date_default_timezone_set ("GMT");
set_error_handler("Storage::onError");
set_exception_handler("Storage::onException");
$script = basename(__FILE__);
if (isset($_SERVER["PHP_SELF"])
        && preg_match("/\/" . str_replace(".", "\\.", $script) . "([\/\?].*)?$/", $_SERVER["PHP_SELF"])
        && (empty($_SERVER["REDIRECT_URL"])))
    (new Storage)->quit(404, "Not Found");
$method = strtoupper($_SERVER["REQUEST_METHOD"]);
if ($method === "OPTIONS"
        && isset($_SERVER["HTTP_ORIGIN"])
        && !isset($_SERVER["HTTP_STORAGE"]))
    (new Storage)->quit(204, "No Content");
if (!isset($_SERVER["HTTP_STORAGE"]))
    (new Storage)->quit(400, "Bad Request", ["Message" => "Missing storage identifier"]);
$storage = $_SERVER["HTTP_STORAGE"];
if (!preg_match(Storage::PATTERN_HEADER_STORAGE, $storage))
    (new Storage)->quit(400, "Bad Request", ["Message" => "Invalid storage identifier"]);
$request = $_SERVER["REQUEST_URI"];
if (isset($_SERVER["REQUEST"]))
    $request = preg_match(Storage::PATTERN_HTTP_REQUEST, $_SERVER["REQUEST"], $request, PREG_UNMATCHED_AS_NULL) ? $request[2] : "";
$xpath = preg_match(Storage::PATTERN_HTTP_REQUEST_URI, $request, $xpath, PREG_UNMATCHED_AS_NULL) ? $xpath[2] : "";
if (preg_match(Storage::PATTERN_HEX, $xpath))
    $xpath = trim(hex2bin(substr($xpath, 1)));
else if (preg_match(Storage::PATTERN_BASE64, $xpath))
    $xpath = trim(base64_decode(substr($xpath, 1)));
else $xpath = trim(urldecode($xpath));
if (strcasecmp("PUT", $method) === 0
        && strlen($xpath ?: "") <= 0)
    $method = "CONNECT";
if (strcmp($xpath, "") === 0
        && !in_array($method, ["CONNECT", "OPTIONS", "POST"]))
    $xpath = "/";
$options = Storage::STORAGE_SHARE_NONE;
if (in_array($method, ["CONNECT", "DELETE", "PATCH", "PUT"]))
    $options |= Storage::STORAGE_SHARE_EXCLUSIVE;
if (in_array($method, ["CONNECT"]))
    $options |= Storage::STORAGE_SHARE_INITIAL;
$storage = Storage::share($storage, $xpath, $options);
try {
    switch ($method) {
        case "CONNECT":
            $storage->doConnect();
        case "OPTIONS":
            $storage->doOptions();
        case "GET":
            $storage->doGet();
        case "POST":
            $storage->doPost();
        case "PUT":
            $storage->doPut();
        case "PATCH":
            $storage->doPatch();
        case "DELETE":
            $storage->doDelete();
        default:
            $storage->quit(405, "Method Not Allowed", ["Allow" => "CONNECT, OPTIONS, GET, POST, PUT, PATCH, DELETE"]);
    }
} finally {
    $storage->close();
}
