<?php

namespace Remp\MailerModule\Generators;

class ArticleLocker
{
    private $placeholder = '<!--[LOCKED_TEXT_PLACEHOLDER]-->';
    private $linkText;
    private $linkUrl;
    private $text;

    public function getLockedPost($post)
    {
        if (stripos($post, '[lock newsletter]') !== false) {
            $lock = '[lock newsletter]';
        } elseif (stripos($post, '[lock]') !== false) {
            $lock = '[lock]';
        } else {
            // no lock, no placeholder
            return $post;
        }

        $parts = explode($lock, $post);
        return $parts[0] . $this->placeholder;
    }

    public function putLockedMessage($post)
    {
        $lockedHtml = <<< HTML
<h2 style="color:#181818;padding:0;margin:0;Margin:0;line-height:1.3;font-weight:bold;text-align:left;margin-bottom:30px;Margin-bottom:30px;font-size:24px;">{$this->text}</h2>
<table class="button primary large"
       style="border-spacing:0;border-collapse:collapse;vertical-align:top;color:#181818;padding:0;margin:0;Margin:0;line-height:1.3;text-align:left;font-family:'Helvetica Neue', Helvetica, Arial;width:auto;margin:0 0 16px 0;Margin:0 0 16px 0;text-align: left;">
    <tbody>
    <tr style="padding:0;vertical-align:top;text-align:left;">
        <td style="padding:0;vertical-align:top;text-align:left;font-size:18px;line-height:1.6;border-collapse:collapse !important;">
            <table class="button primary large"
                   style="border-spacing:0;border-collapse:collapse;vertical-align:top;color:#181818;padding:0;margin:0;Margin:0;line-height:1.3;text-align:left;font-family:'Helvetica Neue', Helvetica, Arial;width:auto;margin:0 0 16px 0;Margin:0 0 16px 0;text-align: left;">
                <tbody>
                <tr style="padding:0;vertical-align:top;text-align:left;">
                    <td style="padding:0;vertical-align:top;font-size:18px;line-height:1.6;text-align:left;color:#ffffff;background:#00A251;border:1px solid #00A251;border-collapse:collapse !important;">
                        <a href="{$this->linkUrl}" title="{$this->linkText}"
                           style="color:#181818;padding:0;margin:0;Margin:0;line-height:1.3;color:#00A251;padding:10px 20px 10px 20px;font-size:20px;font-size:13px;font-weight:normal;color:#ffffff;text-decoration:none;display:inline-block;padding:14px 12px 14px 12px;border:0 solid #00A251;border-radius:3px;">
                            <!--[if gte mso 15]>&nbsp;<![endif]-->
                            {$this->linkText}</a>
                    </td>
                </tr>
                </tbody>
            </table>
        </td>
    </tr>
    </tbody>
</table>
HTML;
        return str_replace($this->placeholder, $lockedHtml, $post);
    }

    /**
     * @param $linkText
     * @param $linkUrl
     * @return $this
     */
    public function setupLockLink($linkText, $linkUrl)
    {
        $this->linkText = $linkText;
        $this->linkUrl = $linkUrl;
        return $this;
    }

    /**
     * @param mixed $text
     * @return ArticleLocker
     */
    public function setLockText($text)
    {
        $this->text = $text;
        return $this;
    }
}
