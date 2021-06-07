<?php

namespace Garradin\Web\Render;

use Parsedown as Parent_Parsedown;

use Garradin\Entities\Files\File;

use Garradin\Utils;

/**
 * Custom Parsedown extension to enable the use of Skriv extensions inside Markdown markup
 *
 * Also adds support for footnotes and Table of Contents
 *
 * @see https://github.com/erusev/parsedown/wiki/Tutorial:-Create-Extensions
 */
class Parsedown extends Parent_Parsedown
{
	protected $skriv;
	protected $toc = [];

	function __construct(?File $file)
	{
		$this->BlockTypes['<'][] = 'SkrivExtension';
		$this->BlockTypes['['][]= 'TOC';

		# identify footnote definitions before reference definitions
		array_unshift($this->BlockTypes['['], 'Footnote');

		# identify footnote markers before before links
		array_unshift($this->InlineTypes['['], 'FootnoteMarker');

		$this->skriv = new Skriv($file);
	}

	protected function blockSkrivExtension(array $line): ?array
	{
		$line = $line['text'];

		if (strpos($line, '<<') === 0 && preg_match('/^<<<?([a-z_]+)((?:(?!>>>?).)*?)(>>>?$|$)/i', trim($line), $match)) {
			$text = $this->skriv->callExtension($match);

			return [
				'char'    => $line[0],
				'element' => [
					'name'                   => 'div',
					'rawHtml'                => $text,
					'allowRawHtmlInSafeMode' => true,
				],
				'complete' => true,
			];
		}

		return null;
	}

	protected function blockHeader($line)
	{
		$block = parent::blockHeader($line);

		if (is_array($block)) {
			if (!isset($block['element']['attributes']['id'])) {
				$block['element']['attributes']['id'] = Utils::transformTitleToURI($block['element']['text']);
			}

			$level = substr($block['element']['name'], 1); // h1, h2... -> 1, 2...
			$id = $block['element']['attributes']['id'];
			$label = $block['element']['text'];

			$this->toc[] = compact('level', 'id', 'label');
		}

		return $block;
	}

	protected function blockTOC(array $line): ?array
	{
		if (!preg_match('/^\[(?:toc|sommaire)\]$/', trim($line['text']))) {
			return null;
		}

		return [
			'char'     => $line['text'][0],
			'complete' => true,
			'element'  => [
				'name'                   => 'div',
				'rawHtml'                => '<toc></toc>',
				'allowRawHtmlInSafeMode' => true,
			],
		];
	}

	public function buildTOC(): string
	{
		if (!count($this->toc)) {
			return '';
		}

		$out = '<div class="toc">';

		$level = 0;

		foreach ($this->toc as $h) {
			if ($h['level'] > $level) {
				$out .= str_repeat('<ol>', $h['level'] - $level);
				$level = $h['level'];
			}
			elseif ($h['level'] < $level) {
				$out .= str_repeat('</ol>', $level - $h['level']);
				$level = $h['level'];
			}

			$out .= sprintf('<li><a href="#%s">%s</a></li>', $h['id'], $h['label']);
		}

		if ($level > 0) {
			$out .= str_repeat('</ol>', $level);
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * Footnotes implementation, inspired by ParsedownExtra
	 * We're not using ParsedownExtra as it's buggy and unmaintained
	 */
	protected function blockFootnote(array $line): ?array
	{
		if (preg_match('/^\[\^(.+?)\]:[ ]?(.*)$/', $line['text'], $matches))
		{
			$block = array(
				'footnotes' => [$matches[1] => $matches[2]],
			);

			return $block;
		}

		return null;
	}

	protected function blockFootnoteContinue(array $line, array $block): ?array
	{
		if ($line['text'][0] === '[' && preg_match('/^\[\^(.+?)\]: ?(.*)$/', $line['text'], $matches))
		{
			$block['footnotes'][$matches[1]] = $matches[2];
			return $block;
		}

		end($block['footnotes']);
		$last = key($block['footnotes']);

		if (isset($block['interrupted']))
		{
			if ($line['indent'] >= 4)
			{
				$block['footnotes'][$last] .= "\n\n" . $line['text'];

				return $block;
			}
		}
		else
		{
			$block['footnotes'][$last] .= "\n" . $line['text'];

			return $block;
		}
	}

	protected function blockFootnoteComplete(array $in)
	{
		$html = '';

		foreach ($in['footnotes'] as $name => $value) {
			$html .= sprintf('<dt id="fn-%s"><a href="#fn-ref-%1$s">%1$s</a></dt><dd>%s</dd>', htmlspecialchars($name), $this->text($value));
		}

		$out = [
			'element' => [
				'name'                   => 'dl',
				'attributes'             => ['class' => 'footnotes'],
				'rawHtml'                => $html,
				'allowRawHtmlInSafeMode' => true,
			],
		];

		return $out;
	}


	protected function inlineFootnoteMarker($Excerpt)
	{
		if (preg_match('/^\[\^(.+?)\]/', $Excerpt['text'], $matches))
		{
			$name = htmlspecialchars($matches[1]);

			$Element = array(
				'name' => 'sup',
				'attributes' => ['id' => 'fn-ref-'.$name],
				'handler' => 'element',
				'text' => array(
					'name' => 'a',
					'attributes' => array('href' => '#fn-'.$name, 'class' => 'footnote-ref'),
					'text' => $name,
				),
			);

			return [
				'extent' => strlen($matches[0]),
				'element' => $Element,
			];
		}
	}


	public function text($text)
	{
		$out = parent::text($text);

		if (false !== strpos($out, '<toc></toc>')) {
			$toc = $this->buildTOC();
			$out = str_replace('<toc></toc>', $toc, $out);
		}

		return $out;
	}
}