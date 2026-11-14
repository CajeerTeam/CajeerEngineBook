<?php
declare(strict_types=1);
namespace CajeerBook;

/** Portable image presentation metadata stored in a standard Markdown link title. */
final class MediaMarkup
{
    public const IMAGE_PREFIX = 'book-image:v1:';
    public const ATTACHMENT_TITLE = 'book-attachment:v1';

    public static function imageOptions(?string $title): ?array
    {
        if ($title === null || !str_starts_with($title, self::IMAGE_PREFIX) || strlen($title) > 12000) return null;
        try {
            $data = json_decode(rawurldecode(substr($title, strlen(self::IMAGE_PREFIX))), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) { return null; }
        if (!is_array($data) || !in_array($data['width'] ?? null, [25, 50, 75, 100], true)
            || !in_array($data['align'] ?? null, ['left', 'center', 'right'], true)
            || !is_string($data['caption'] ?? null) || strlen($data['caption']) > 2000
            || preg_match('/[\x00-\x1f\x7f]/', $data['caption'])) return null;
        return ['width'=>$data['width'], 'align'=>$data['align'], 'caption'=>$data['caption']];
    }

    public static function imageHtml(string $html, array $options): string
    {
        $width = in_array($options['width'], [25,50,75,100], true) ? $options['width'] : 100;
        $align = in_array($options['align'], ['left','center','right'], true) ? $options['align'] : 'center';
        $caption = $options['caption'];
        return '<figure class="book-image book-image-'.$width.' book-image-'.$align.'" data-book-image>'.$html
            .($caption !== '' ? '<figcaption>'.Markdown::e($caption).'</figcaption>' : '').'</figure>';
    }

    public static function attachmentHtml(string $html): string
    {
        return '<p class="book-attachment"><span class="book-attachment-label">Attachment</span> '.$html.'</p>';
    }
}
