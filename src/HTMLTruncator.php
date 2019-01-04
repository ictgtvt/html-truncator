<?php

namespace ictgtvt;

use Masterminds\HTML5;

/**
 * Class HtmlTruncator
 * @package App\Utilities
 *
 * Re-written of https://github.com/judev/php-htmltruncator
 */
class HtmlTruncator {
    // These tags are allowed to have an ellipsis inside
    public static $ellipsable_tags = array(
        'p', 'ol', 'ul', 'li',
        'div', 'header', 'article', 'nav',
        'section', 'footer', 'aside',
        'dd', 'dt', 'dl',
    );

    public static $self_closing_tags = array(
        'br', 'hr', 'img',
    );

    protected $is_ellipse_added = false;

    /**
     * @param string $htmlString
     * @param int $length
     * @return string
     *
     * Example:
     *     $truncator = new HtmlTruncator();
     *     echo $truncator->truncate($html, 400);
     */
    public function truncate($htmlString, $length = 100)
    {
        $html5 = new HTML5();

        /** @var \DOMDocument $dom */
        $dom = $html5->loadHTML('<div>' . $htmlString . '</div>');

        // firstChild is Doctype node
        $rootNode = $dom->lastChild->lastChild;

        $this->is_ellipse_added = false;

        list($htmlString, $length) = $this->_truncateNode($dom, $rootNode, $length, true);

        return $htmlString;
    }

    /**
     * @param \DOMDocument $dom
     * @param \DOMNode $node
     * @param int $length
     *
     * @return array
     */
    protected function _truncateInsideNode($dom, $node, $length)
    {
        $htmlString = '';
        $remaining = $length;

        if ($node->nodeName == 'tr') { // Do not truncate inside tr
            return [$dom->saveXML($node), $length - mb_strlen($node->textContent)];
        }

        /** @var \DOMNode $childNode */
        foreach ($node->childNodes as $childNode) {
            if ($childNode->nodeType == XML_ELEMENT_NODE) {
                list($htmlString_, $nb) = $this->_truncateNode($dom, $childNode, $remaining);
            } elseif ($childNode->nodeType == XML_TEXT_NODE) {
                list($htmlString_, $nb) = $this->_truncateText($dom, $childNode, $remaining);
            } else {
                $htmlString_ = '';
                $nb = 0;
            }

            $remaining -= $nb;

            $htmlString .= $htmlString_;

            if ($remaining < 0) {
                if ($this->_ellipsable($node) && !$this->is_ellipse_added) {
                    $htmlString = preg_replace('/(?:[\s\pP]+|(?:&(?:[a-z]+|#[0-9]+);?))*$/', '', $htmlString) . '...';
                    $this->is_ellipse_added = true;
                }

                break;
            }
        }

        return [$htmlString, $remaining];
    }

    /**
     * @param \DOMDocument $dom
     * @param \DOMNode $node
     * @param int $length
     * @param bool $isRootNode
     * @return array
     */
    protected function _truncateNode($dom, $node, $length, $isRootNode = false)
    {
        if ($length === 0 && !$this->_ellipsable($node)) {
            return ['', 1];
        }

        list($htmlString, $remaining) = $this->_truncateInsideNode($dom, $node, $length);

        if (0 === mb_strlen($htmlString)) {
            return [in_array(mb_strtolower($node->nodeName), $this::$self_closing_tags) ? $dom->saveXML($node) : '', $length - $remaining];
        }

        while ($node->firstChild) {
            $node->removeChild($node->firstChild);
        }

        $newNode = $dom->createDocumentFragment();
        $newNode->appendXML($htmlString);

        $node->appendChild($newNode);

        if ($isRootNode && !empty($node->lastChild->lastChild->previousSibling)) { // Remove table if it only has 1 row
            $lastNode = $node->lastChild->lastChild->previousSibling;

            if (strtolower($lastNode->nodeName) == 'table') {
                $xpath = new \DOMXpath($lastNode->ownerDocument);

                $tr_elements = $xpath->query("//table[last()]/tr");

                if ($tr_elements->count() == 1) { // Table rows must be >= 3 for cutting
                    $node->lastChild->removeChild($lastNode); // Remove table
                }
            }
        }

        return [$dom->saveXML($node), $length - $remaining];
    }

    /**
     * @param \DOMDocument $dom
     * @param \DOMNode $node
     * @param $length
     *
     * @return array
     */
    protected function _truncateText($dom, $node, $length)
    {
        $htmlString = $node->ownerDocument->saveXML($node);

        preg_match_all('/\s*\S+/', $htmlString, $words);

        $words = $words[0];

        $count = mb_strlen($htmlString);

        if ($count <= $length && $length > 0) {
            return [$htmlString, $count];
        }

        if (count($words) > 1) {
            $content = '';
            $added = 0;

            foreach ($words as $word) {
                if (mb_strlen($content) + mb_strlen($word) > $length) {
                    break;
                }

                $added += mb_strlen($word);
                $content .= $word;
            }

            return [$content, $added];
        }

        return [mb_substr($node->textContent, 0, $length), $count];
    }

    protected function _ellipsable($node) {
        return ($node instanceof \DOMDocument) || in_array(mb_strtolower($node->nodeName), static::$ellipsable_tags);
    }
}
