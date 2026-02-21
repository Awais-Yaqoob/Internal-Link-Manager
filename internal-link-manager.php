<?php
/**
 * Plugin Name: Internal Link Manager By Awaia Yaqoob
 * Description: Safely inserts internal links server-side from JSON mappings. Never inserts into the first <p> (hero) for pages/CPTs, never into template/shortcode embeds (Elementor/Elementskit/ekit etc.), single-text-node insertions only. Version 1.9.2
 * Version: 1.9.2
 * Author: BloomHouse Marketing LLC
 * License: GPLv2
 */
if (!defined('ABSPATH')) exit;

class ILM_Plugin {
    public static $option_name = 'ilm_keyword_mappings_json';
    public static $option_posttypes = 'ilm_apply_post_types';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_filter('the_content', [__CLASS__, 'maybe_insert_links'], 999);
        if (did_action('elementor/loaded') || defined('ELEMENTOR_VERSION')) {
            add_filter('elementor/frontend/the_content', [__CLASS__, 'maybe_insert_links'], 999);
        } else {
            add_action('elementor/loaded', function() {
                add_filter('elementor/frontend/the_content', [__CLASS__, 'maybe_insert_links'], 999);
            }, 20);
        }
    }

    public static function admin_menu() {
        add_menu_page('Internal Link Manager', 'Internal Links', 'manage_options', 'ilm-settings', [__CLASS__, 'settings_page']);
    }

    public static function register_settings() {
        register_setting('ilm_settings_group', self::$option_name, [__CLASS__, 'sanitize_json']);
        register_setting('ilm_settings_group', self::$option_posttypes);
    }

    public static function sanitize_json($value) {
        if (empty($value)) return '';
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            add_settings_error(self::$option_name, 'ilm_json_error', 'Invalid JSON format.');
            return get_option(self::$option_name, '');
        }
        $out = [];
        foreach ($decoded as $obj) {
            if (empty($obj['keywords']) || empty($obj['url'])) continue;
            $keywords = array_values(array_filter(array_map('trim', (array)$obj['keywords'])));
            $url = esc_url_raw($obj['url']);
            if (empty($keywords) || empty($url)) continue;
            $out[] = ['keywords' => $keywords, 'url' => $url];
        }
        return wp_json_encode($out);
    }

    public static function settings_page() {
        if (!current_user_can('manage_options')) return;
        $json = get_option(self::$option_name, '');
        $posttypes = get_option(self::$option_posttypes, []);
        $all_types = get_post_types(['public' => true], 'objects');
        ?>
        <div class="wrap">
            <h1>Internal Link Manager</h1>
            <form method="post" action="options.php">
                <?php settings_fields('ilm_settings_group'); ?>
                <?php do_settings_sections('ilm_settings_group'); ?>
                <h2>Mappings (JSON)</h2>
                <p>Provide a JSON array of objects. Each object must include <code>keywords</code> (array) and <code>url</code> (string).</p>
                <textarea name="<?php echo esc_attr(self::$option_name); ?>" rows="16" cols="120" style="width:100%;font-family:monospace;"><?php echo esc_textarea($json); ?></textarea>
                <h2>Apply To Post Types</h2>
                <table class="form-table"><tbody>
                <?php foreach ($all_types as $ptype => $obj): ?>
                    <tr>
                        <th><?php echo esc_html($obj->labels->singular_name); ?></th>
                        <td><input type="checkbox" name="<?php echo esc_attr(self::$option_posttypes); ?>[]" value="<?php echo esc_attr($ptype); ?>" <?php checked(in_array($ptype, (array)$posttypes)); ?> /></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php submit_button(); ?>
            </form>
            <h2>Notes</h2>
            <ul>
                <li>Targets only <code>&lt;p&gt;</code> and <code>&lt;div&gt;</code> tags inside the body (server-side).</li>
                <li><strong>Never adds links to the first &lt;p&gt; (hero/intro) for pages/CPTs; blog posts are allowed.</strong></li>
                <li>Skips headings, FAQ, tables, headers, footers and buttons.</li>
                <li>Does not insert into template/shortcode embed wrappers (Elementor templates, Elementskit/EKit widget areas, common shortcode wrappers).</li>
                <li>Detects existing URLs inside embedded templates to avoid duplicates across the page.</li>
                <li>Each mapping is used once per page; each URL once per page.</li>
            </ul>
        </div>
        <?php
    }

    public static function maybe_insert_links($content) {
        if (is_admin() && !wp_doing_ajax()) return $content;
        if (defined('REST_REQUEST') && REST_REQUEST) return $content;
        if (!is_singular()) return $content;
        global $post;
        if (!$post) return $content;

        $post_type = get_post_type($post->ID);
        $apply_types = get_option(self::$option_posttypes, []);
        if (!empty($apply_types) && !in_array($post_type, (array)$apply_types)) return $content;

        $json = get_option(self::$option_name, '');
        if (empty($json)) return $content;
        $mappings = json_decode($json, true);
        if (!is_array($mappings) || empty($mappings)) return $content;

        $skip_first_block = ($post_type !== 'post');
        $current_permalink = get_permalink($post);
        $current_title = trim(strip_tags($post->post_title ?? ''));
        $current_title_lc = mb_strtolower($current_title, 'UTF-8');
        $current_slug = $post->post_name ?? '';
        $current_slug_lc = mb_strtolower($current_slug, 'UTF-8');

        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        @$dom->loadHTML('<?xml encoding="utf-8" ?><body>' . $content . '</body>');
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) return $content;

        $firstBlock = null;
        if ($skip_first_block) {
            $firstP = $xpath->query('//body//p')->item(0);
            if ($firstP && !self::is_inside_template_embed($firstP) && !self::is_inside_disallowed_section($firstP)) {
                $firstBlock = $firstP;
            } else {
                foreach ($body->childNodes as $child) {
                    if ($child->nodeType === XML_ELEMENT_NODE) {
                        $tag = strtolower($child->nodeName);
                        if (in_array($tag, ['p','div'], true)) {
                            if (!self::is_inside_template_embed($child) && !self::is_inside_disallowed_section($child)) {
                                $firstBlock = $child;
                                break;
                            }
                        }
                    }
                }
            }
        }

        // === Prepare entries ===
        $entries = [];
        foreach ($mappings as $map) {
            if (empty($map['keywords']) || empty($map['url'])) continue;
            $kwList = array_values(array_filter(array_map('trim', (array)$map['keywords'])));
            if (empty($kwList)) continue;
            $map_url = esc_url($map['url']);
            if (self::urls_equal($map_url, $current_permalink)) continue;

            $skip_for_keyword_match = false;

            // ✅ Fix: Only skip title/slug keywords for NON-post types
            if ($post_type !== 'post') {
                foreach ($kwList as $kw) {
                    $kw_lc = mb_strtolower(trim($kw), 'UTF-8');
                    if ($kw_lc === '') continue;
                    if ($kw_lc === $current_title_lc || mb_stripos($current_title_lc, $kw_lc, 0, 'UTF-8') !== false) {
                        $skip_for_keyword_match = true;
                        break;
                    }
                    if ($kw_lc === $current_slug_lc || mb_stripos($current_slug_lc, $kw_lc, 0, 'UTF-8') !== false) {
                        $skip_for_keyword_match = true;
                        break;
                    }
                }
            }

            if ($skip_for_keyword_match) continue;

            usort($kwList, function($a,$b){ return mb_strlen($b,'UTF-8') - mb_strlen($a,'UTF-8'); });
            $entries[] = ['keywords'=>$kwList, 'url'=>$map_url, 'applied'=>false];
        }

        if (empty($entries)) return $content;

        // === consolidate by normalized URL ===
        $entriesByUrl = [];
        foreach ($entries as $entry) {
            $entry_url_norm = self::normalize_url($entry['url']);
            $key = $entry_url_norm !== '' ? $entry_url_norm : md5($entry['url']);
            if (!isset($entriesByUrl[$key])) {
                $entriesByUrl[$key] = $entry;
            } else {
                $merged = array_merge($entriesByUrl[$key]['keywords'], $entry['keywords']);
                $merged = array_values(array_unique($merged));
                usort($merged, function($a,$b){ return mb_strlen($b,'UTF-8') - mb_strlen($a,'UTF-8'); });
                $entriesByUrl[$key]['keywords'] = $merged;
            }
        }
        $entries = array_values($entriesByUrl);

        // === existing URLs detection ===
        $urlApplied = [];
        $urlAppliedOriginal = [];
        $allAnchorNodes = $xpath->query('//body//a[@href]');
        foreach ($allAnchorNodes as $aNode) {
            $href = trim($aNode->getAttribute('href') ?: '');
            if ($href === '' || $href === '#') continue;
            $h_norm = self::normalize_url($href);
            if ($h_norm !== '') $urlApplied[$h_norm] = true;
            $urlAppliedOriginal[] = $href;
        }
        $htmlLinks = self::extract_urls_from_html($content);
        foreach ($htmlLinks as $htmlUrl) {
            $h_norm = self::normalize_url($htmlUrl);
            if ($h_norm !== '') $urlApplied[$h_norm] = true;
            $urlAppliedOriginal[] = $htmlUrl;
        }
        $urlAppliedOriginal = array_values(array_unique($urlAppliedOriginal));

        // === mark already present URLs ===
        foreach ($entries as $idx=>$entry) {
            if ($entry['applied']) continue;
            $entry_url_norm = self::normalize_url($entry['url']);
            $found = false;
            if ($entry_url_norm !== '' && !empty($urlApplied[$entry_url_norm])) {
                $found = true;
            } else {
                foreach ($urlAppliedOriginal as $existingUrl) {
                    if (self::urls_equal($entry['url'],$existingUrl)) {
                        $found = true;
                        break;
                    }
                }
            }
            if ($found) {
                foreach ($entries as $k=>$e) {
                    if ($entries[$k]['applied']) continue;
                    if (self::urls_equal($entries[$k]['url'],$entry['url'])) $entries[$k]['applied']=true;
                }
            }
        }

        // === insertion loop (unchanged) ===
        $targets = $xpath->query('//body//p | //body//div');
        foreach ($entries as $ek => &$entry) {
            if ($entry['applied']) continue;
            $entry_norm = self::normalize_url($entry['url']);
            if ($entry_norm !== '' && !empty($urlApplied[$entry_norm])) {
                $entry['applied'] = true;
                continue;
            }

            $kwPatterns = [];
            foreach ($entry['keywords'] as $kw) {
                $kw = trim($kw);
                if ($kw === '') continue;
                $kwPatterns[$kw] = '/(?<!\p{L})'.preg_quote($kw,'/').'(?!\p{L})/iu';
            }
            if (empty($kwPatterns)) {
                $entry['applied'] = true;
                continue;
            }

            $insertedThisEntry = false;
            foreach ($targets as $block) {
                if ($insertedThisEntry) break;
                if ($firstBlock && $block->isSameNode($firstBlock)) continue;
                if (self::is_inside_disallowed_section($block)) continue;
                if (self::is_inside_template_embed($block)) continue;
                $innerHtml = '';
                foreach ($block->childNodes as $c) $innerHtml .= $dom->saveHTML($c);
                if (stripos($innerHtml,'<a')!==false) continue;
                $textNodes = self::collect_text_nodes($block);
                if (empty($textNodes)) continue;
                $combined = '';
                $nodeLens = [];
                foreach ($textNodes as $tn) {
                    $val = str_replace("\xC2\xA0",' ',$tn->nodeValue);
                    $combined .= $val;
                    $nodeLens[] = mb_strlen($val,'UTF-8');
                }
                if ($combined === '') continue;

                // --- keyword match & DOM insertion ---
                foreach ($kwPatterns as $kw=>$pat) {
                    if (!@preg_match($pat,$combined,$m,PREG_OFFSET_CAPTURE)) continue;
                    if (empty($m[0])) continue;
                    $matchText = $m[0][0];
                    $byteOffset = $m[0][1];
                    $charOffset = mb_strlen(substr($combined,0,$byteOffset),'UTF-8');
                    $matchLenChars = mb_strlen($matchText,'UTF-8');

                    // locate start/end nodes
                    $acc = 0;
                    $startNodeIdx=null; $startOffsetInNode=null;
                    for ($i=0,$ncount=count($nodeLens); $i<$ncount; $i++) {
                        $len = $nodeLens[$i];
                        if ($charOffset>=$acc && $charOffset<$acc+$len) {
                            $startNodeIdx=$i;
                            $startOffsetInNode=$charOffset-$acc;
                            break;
                        }
                        $acc+=$len;
                    }
                    $matchEndCharIndex=$charOffset+$matchLenChars;
                    $acc=0;
                    $endNodeIdx=null; $endOffsetInNode=null;
                    for ($i=0,$ncount=count($nodeLens); $i<$ncount; $i++) {
                        $len=$nodeLens[$i];
                        if ($matchEndCharIndex>$acc && $matchEndCharIndex<=$acc+$len) {
                            $endNodeIdx=$i;
                            $endOffsetInNode=$matchEndCharIndex-$acc;
                            break;
                        }
                        $acc+=$len;
                    }
                    if ($startNodeIdx===null || $endNodeIdx===null) continue;

                    $startNode = $textNodes[$startNodeIdx];
                    $endNode = $textNodes[$endNodeIdx];
                    if (!$startNode->parentNode || !$endNode->parentNode) continue;

                    if ($startNode->isSameNode($endNode)) {
                        $nodeText = str_replace("\xC2\xA0",' ',$startNode->nodeValue);
                        $beforeText = ($startOffsetInNode>0)?mb_substr($nodeText,0,$startOffsetInNode,'UTF-8'):'';
                        $midText = mb_substr($nodeText,$startOffsetInNode,$matchLenChars,'UTF-8');
                        $afterText = ($startOffsetInNode+$matchLenChars<mb_strlen($nodeText,'UTF-8'))?mb_substr($nodeText,$startOffsetInNode+$matchLenChars,null,'UTF-8'):'';
                        $parent = $startNode->parentNode;
                        if ($beforeText!=='') $parent->insertBefore($dom->createTextNode($beforeText),$startNode);
                        $a=$dom->createElement('a',htmlspecialchars($midText,ENT_XML1|ENT_COMPAT,'UTF-8'));
                        $a->setAttribute('href',$entry['url']);
                        $parent->insertBefore($a,$startNode);
                        if ($afterText!=='') $parent->insertBefore($dom->createTextNode($afterText),$startNode);
                        $parent->removeChild($startNode);
                    } else {
                        $startVal=str_replace("\xC2\xA0",' ',$startNode->nodeValue);
                        $startBefore=($startOffsetInNode>0)?mb_substr($startVal,0,$startOffsetInNode,'UTF-8'):'';
                        $matchTextSingle=mb_substr($startVal,$startOffsetInNode,$matchLenChars,'UTF-8');
                        $parent=$startNode->parentNode;
                        if ($startBefore!=='') $parent->insertBefore($dom->createTextNode($startBefore),$startNode);
                        $a=$dom->createElement('a',htmlspecialchars($matchTextSingle,ENT_XML1|ENT_COMPAT,'UTF-8'));
                        $a->setAttribute('href',$entry['url']);
                        $parent->insertBefore($a,$startNode);
                        $remaining=mb_substr($startVal,$startOffsetInNode+$matchLenChars,null,'UTF-8');
                        $parent->removeChild($startNode);
                        if ($remaining!=='') $parent->appendChild($dom->createTextNode($remaining));
                    }

                    $entry['applied']=true;
                    if ($entry_norm!=='') $urlApplied[$entry_norm]=true;
                    $urlAppliedOriginal[]=$entry['url'];
                    $insertedThisEntry=true;
                    break;
                }
                if ($insertedThisEntry) break;
            }
        }
        unset($entry);

        $out='';
        foreach ($body->childNodes as $child) $out.=$dom->saveHTML($child);
        return $out;
    }

//    Pasted the old one
	
	
	private static function extract_urls_from_html($html) {
        $urls = [];
        if (!is_string($html) || $html === '') return [];
        if (preg_match_all('/<a\s+[^>]*?href\s*=\s*(["\']?)([^"\'\s>]+)\1[^>]*>/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $u = trim($m[2]);
                if ($u !== '' && $u !== '#') $urls[] = $u;
            }
        }
        if (preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', $html, $m2)) {
            foreach ($m2[1] as $u) {
                $u = trim($u);
                if ($u !== '' && $u !== '#' && !in_array($u, $urls, true)) $urls[] = $u;
            }
        }
        $candidates = [];
        if (preg_match_all('/\b[^\s=<>]+=(["\'])(.*?)\1/s', $html, $attrMatches)) {
            foreach ($attrMatches[2] as $av) if ($av !== '') $candidates[] = $av;
        }
        if (preg_match_all('/<!--(.*?)-->/s', $html, $comMatches)) {
            foreach ($comMatches[1] as $c) if ($c !== '') $candidates[] = $c;
        }
        $candidates[] = $html;
        $urlRegex = '#(?:(?:https?:)?//[^\s"\'<>]+)|(?:https?://[^\s"\'<>]+)#i';
        foreach ($candidates as $cand) {
            if (preg_match_all($urlRegex, $cand, $um)) {
                foreach ($um[0] as $found) {
                    $f = trim($found);
                    $f = rtrim($f, '.,;:)\]\}>"\'');
                    if ($f !== '' && $f !== '#' && !in_array($f, $urls, true)) $urls[] = $f;
                }
            }
        }
        return array_values(array_unique($urls));
    }

    private static function normalize_url($u) {
        $u = trim((string)$u);
        if ($u === '') return '';
        if (preg_match('#^(mailto:|tel:|javascript:|\#)#i', $u)) return '';
        if (strpos($u, '//') === 0) {
            $home = get_home_url();
            $scheme = parse_url($home, PHP_URL_SCHEME) ?: 'https';
            $u = $scheme . ':' . $u;
        }
        if (preg_match('#^/#', $u) && !preg_match('#^https?://#i', $u)) {
            $u = rtrim(get_home_url(), '/') . $u;
        } elseif (!preg_match('#^https?://#i', $u)) {
            $u = rtrim(get_home_url(), '/') . '/' . ltrim($u, '/');
        }
        $u = preg_replace('/[#?].*$/', '', $u);
        $p = wp_parse_url($u);
        if (!$p || empty($p['host'])) return rtrim(strtolower($u), '/');
        $scheme = isset($p['scheme']) ? strtolower($p['scheme']) . '://' : 'http://';
        $host = strtolower($p['host']);
        if (strpos($host, 'www.') === 0) $host = substr($host, 4);
        $path = isset($p['path']) ? $p['path'] : '';
        $path = preg_replace('#/index\.(php|html?|htm)$#i', '/', $path);
        $path = rtrim($path, '/');
        $result = $scheme . $host . $path;
        return rtrim($result, '/');
    }

    private static function is_inside_disallowed_section($node) {
        while ($node && $node->parentNode) {
            $node = $node->parentNode;
            if (!$node || $node->nodeType !== XML_ELEMENT_NODE) continue;
            $tag = strtolower($node->nodeName);
            if (in_array($tag, ['header','footer','table','thead','tfoot','th','h1','h2','h3','h4','h5','h6','button','ol','ul','li'], true)) return true;
            if ($node->hasAttributes()) {
                $classAttr = strtolower($node->getAttribute('class') ?? '');
                if ($classAttr !== '' && preg_match('/\b(faq|accordion|question|toggle|collapse|panel|ep-title|ep-title-text|heading-text|question-title|hero|intro|banner|nav|menu|button)\b/i', $classAttr)) return true;
                $roleAttr = strtolower($node->getAttribute('role') ?? '');
                if (in_array($roleAttr, ['heading','button','navigation','banner','menu','presentation','none'], true)) return true;
                if ($node->attributes->getNamedItem('aria-controls') || $node->attributes->getNamedItem('aria-expanded') || $node->attributes->getNamedItem('aria-haspopup')) return true;
            }
        }
        return false;
    }

    private static function is_inside_template_embed($node) {
        $cur = $node;
        while ($cur && $cur->nodeType === XML_ELEMENT_NODE) {
            if ($cur->hasAttributes()) {
                foreach ($cur->attributes as $a) {
                    $an = strtolower($a->nodeName);
                    $av = strtolower($a->nodeValue ?? '');
                    if (($an === 'data-elementor-post-type' && strpos($av, 'elementor_library') !== false) ||
                        ($an === 'data-elementor-type' && strpos($av, 'elementor_library') !== false) ||
                        $an === 'data-elementskit-widgetarea-key' ||
                        $an === 'data-elementskit-widgetarea-index' ||
                        ($an === 'data-elementor-post-type' && strpos($av, 'elementskit_content') !== false) ||
                        in_array($an, ['data-shortcode','data-template','data-wp-editor','data-elementkit-widgetid','data-elementkit-widgetarea-key'], true)
                    ) return true;
                }
            }
            $classAttr = strtolower($cur->getAttribute('class') ?? '');
            if ($classAttr !== '') {
                if (preg_match('/\b(elementor-widget-shortcode|elementor-shortcode|widget_text|widget_html|wpb_wrapper|vc_row|vc_column|shortcode|ti-widget|trustindex|wp-block-shortcode|elementor-template-wrap|elementor-widget-template|avia_shortcode|elementor-widget-elementskit-advanced-slider|elementskit-advanced-slider|ekit-wid-con|ekit-widget-area-container|widgetarea_warper|widgetarea_warper_editable|elementskit-widgetarea|elementskit-widget-area)\b/i', $classAttr)) {
                    return true;
                }
                if (strpos($classAttr, 'elementskit-advanced-slider') !== false) return true;
            }
            $tag = strtolower($cur->nodeName);
            if (in_array($tag, ['template','aside'], true)) return true;
            $cur = $cur->parentNode;
            if (!$cur || $cur->nodeType !== XML_ELEMENT_NODE) break;
        }
        return false;
    }

    private static function is_descendant_or_same($node, $ancestor) {
        if (!$node || !$ancestor) return false;
        if ($node->isSameNode($ancestor)) return true;
        $cur = $node;
        while ($cur && $cur->parentNode) {
            $cur = $cur->parentNode;
            if ($cur && $cur->isSameNode($ancestor)) return true;
        }
        return false;
    }

    private static function collect_text_nodes($element) {
        $nodes = [];
        $walker = new RecursiveIteratorIterator(new RecursiveDOMIterator($element), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($walker as $n) {
            if ($n->nodeType === XML_TEXT_NODE && trim($n->nodeValue) !== '') {
                $ancestor = $n->parentNode;
                $skip = false;
                while ($ancestor && $ancestor->nodeType === XML_ELEMENT_NODE) {
                    $tag = strtolower($ancestor->nodeName);
                    if ($tag === 'a' || in_array($tag, ['header','footer','table','thead','tfoot','th','h1','h2','h3','h4','h5','h6','button','ol','ul','li'], true)) {
                        $skip = true;
                        break;
                    }
                    if (self::is_inside_template_embed($ancestor)) {
                        $skip = true;
                        break;
                    }
                    $classAttr = strtolower($ancestor->getAttribute('class') ?? '');
                    $roleAttr = strtolower($ancestor->getAttribute('role') ?? '');
                    if ($classAttr !== '' && preg_match('/\b(faq|accordion|question|toggle|collapse|panel|ep-title|heading-text|question-title|hero|intro|banner)\b/i', $classAttr)) {
                        $skip = true;
                        break;
                    }
                    if (in_array($roleAttr, ['heading','button','navigation','banner','menu','presentation','none'], true)) {
                        $skip = true;
                        break;
                    }
                    $ancestor = $ancestor->parentNode;
                }
                if (!$skip) $nodes[] = $n;
            }
        }
        return $nodes;
    }

    private static function urls_equal($u1, $u2) {
        $n1 = self::normalize_url($u1);
        $n2 = self::normalize_url($u2);
        if ($n1 === '' || $n2 === '') return false;
        return $n1 === $n2;
    }
	
	
	
	
	
	
}

class RecursiveDOMIterator implements RecursiveIterator {
    private $position = 0;
    private $nodeList = [];

    public function __construct($domNode) {
        if ($domNode->hasChildNodes()) foreach ($domNode->childNodes as $child) $this->nodeList[] = $child;
    }
    public function rewind(): void { $this->position = 0; }
    public function valid(): bool { return isset($this->nodeList[$this->position]); }
    public function key(): mixed { return $this->position; }
    public function current(): mixed { return $this->nodeList[$this->position]; }
    public function next(): void { $this->position++; }
    public function hasChildren(): bool { return $this->current()->hasChildNodes(); }
    public function getChildren(): ?RecursiveIterator { return new self($this->current()); }
}

ILM_Plugin::init();
