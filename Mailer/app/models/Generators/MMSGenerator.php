<?php

namespace Remp\MailerModule\Generators;

use Nette\Application\UI\Form;
use Remp\MailerModule\Api\v1\Handlers\Mailers\PreprocessException;
use Remp\MailerModule\Components\GeneratorWidgets\Widgets\MMSWidget;
use Remp\MailerModule\Repository\SourceTemplatesRepository;
use Tomaj\NetteApi\Params\InputParam;
use GuzzleHttp\Client;

class MMSGenerator implements IGenerator
{
    protected $mailSourceTemplateRepository;

    public $onSubmit;

    public function __construct(SourceTemplatesRepository $mailSourceTemplateRepository)
    {
        $this->mailSourceTemplateRepository = $mailSourceTemplateRepository;
    }

    public function apiParams()
    {
        return [
            new InputParam(InputParam::TYPE_POST, 'source_template_id', InputParam::REQUIRED),
            new InputParam(InputParam::TYPE_POST, 'mms_html', InputParam::REQUIRED),
            new InputParam(InputParam::TYPE_POST, 'url', InputParam::REQUIRED),
            new InputParam(InputParam::TYPE_POST, 'title', InputParam::REQUIRED),
            new InputParam(InputParam::TYPE_POST, 'sub_title', InputParam::OPTIONAL),
            new InputParam(InputParam::TYPE_POST, 'image_url', InputParam::OPTIONAL),
            new InputParam(InputParam::TYPE_POST, 'image_title', InputParam::OPTIONAL),
            new InputParam(InputParam::TYPE_POST, 'from', InputParam::REQUIRED),
        ];
    }

    public function getWidgets()
    {
        return [MMSWidget::class];
    }

    public function process($values)
    {
        $helpers = new WordpressHelpers();
        $sourceTemplate = $this->mailSourceTemplateRepository->find($values->source_template_id);

        $post = $values->mms_html;
        $lockedPost = $this->getLockedHtml($post, $values->url);

        list(
            $captionTemplate,
            $captionWithLinkTemplate,
            $liTemplate,
            $hrTemplate,
            $spacerTemplate,
            $imageTemplate
        ) = $this->getTemplates();

        $rules = [
            // remove shortcodes
            "/\[greybox\].*?\[\/greybox\]/is" => "",
            "/\[pullboth.*?\/pullboth\]/is" => "",
            "/<script.*?\/script>/is" => "",
            "/\[iframe.*?\]/is" => "",
            '/\[\/?lock\]/i' => "",
            '/\[lock newsletter\]/i' => "",
            '/\[lock\]/i' => "",

            // remove iframes
            "/<iframe.*?\/iframe>/is" => "",

            // remove paragraphs
            '/<p.*?>(.*?)<\/p>/is' => "$1",
            '/<span.*?>(.*?)<\/span>/is' => "$1",

            // replace em-s
            "/<em.*?>(.*?)<\/em>/is" => "<i style=\"margin:0 0 0 26px;Margin:0 0 0 26px;color:#181818;padding:0;margin:0;Margin:0;line-height:1.3;font-size:18px;line-height:1.6;margin-bottom:26px;Margin-bottom:26px;line-height:160%;text-align:left;font-weight:normal;word-wrap:break-word;-webkit-hyphens:auto;-moz-hyphens:auto;hyphens:auto;border-collapse:collapse !important;\">$1</i><br>",

            // remove new lines from inside caption shortcode
            "/\[caption.*?\/caption\]/is" => function ($matches) {
                return str_replace(array("\n\r", "\n", "\r"), '', $matches[0]);
            },

            // replace captions
            '/\[caption.*?\].*?href="(.*?)".*?src="(.*?)".*?\/a>(.*?)\[\/caption\]/im' => $captionWithLinkTemplate,
            '/\[caption.*?\].*?src="(.*?)".*?\/>(.*?)\[\/caption\]/im' => $captionTemplate,

            // replace link shortcodes
            '/\[articlelink.*?id="(.*?)"/is' => "<a href=\"https://dennikn.sk/$1\" style=\"color:#181818;padding:0;margin:0;Margin:0;line-height:1.3;color:#5050f4;text-decoration:none;\">$2</a><br><br>",

            // replace hrefs
            '/<a.*?href="(.*?)".*?>(.*?)<\/a>/is' => '<a href="$1" title="$2" style="color:#181818;padding:0;margin:0;Margin:0;line-height:1.3;color:#5050f4;text-decoration:none;">$2</a>',

            // replace h2
            '/<h2.*?>(.*?)<\/h2>/is' => '<h2 style="color:#181818;padding:0;margin:0;Margin:0;line-height:1.3;font-weight:bold;text-align:left;margin-bottom:30px;Margin-bottom:30px;font-size:24px;">$1</h2>' . PHP_EOL,

            // replace images
            '/<img.*?src="(.*?)".*?>/is' => $imageTemplate,

            // replace ul & /ul
            '/<ul>/is' => '<table style="border-spacing:0;border-collapse:collapse;vertical-align:top;color:#181818;padding:0;margin:0;Margin:0;line-height:1.3;text-align:left;font-family:\'Helvetica Neue\', Helvetica, Arial;width:100%;"><tbody>',

            '/<\/ul>/is' => '</tbody></table>' . PHP_EOL,

            // replace li
            '/<li>(.*?)<\/li>/is' => $liTemplate,

            // hr
            '/(<hr>|<hr \/>)/is' => $hrTemplate,

            // parse embedds
            '/^\s*(http|https)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,3}(\/\S*)?\s*$/im' => array($this, "parseEmbedd"),

            // remove br from inside of a
            '/<a.*?\/a>/is' => function ($matches) {
                return str_replace('<br />', '', $matches[0]);
            }
        ];


        foreach ($rules as $rule => $replace) {
            if (is_array($replace) || is_callable($replace)) {
                $post = preg_replace_callback($rule, $replace, $post);
                $lockedPost = preg_replace_callback($rule, $replace, $lockedPost);
            } else {
                $post = preg_replace($rule, $replace, $post);
                $lockedPost = preg_replace($rule, $replace, $lockedPost);
            }
        }

        // wrap text in paragraphs
        $post = $helpers->wpautop($post);
        $lockedPost = $helpers->wpautop($lockedPost);

        // fix pees
        list($post, $lockedPost) = preg_replace('/<p>/is', "<p style=\"margin:0 0 0 26px;Margin:0 0 0 26px;color:#181818;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;font-weight:normal;padding:0;margin:0;Margin:0;text-align:left;line-height:1.3;font-size:18px;line-height:1.6;margin-bottom:26px;Margin-bottom:26px;line-height:160%;\">", [$post, $lockedPost]);

        $imageHtml = '';

        if (isset($values->image_title) && isset($values->image_url)) {
            $imageHtml = str_replace('$1', $values->image_url, $captionTemplate);
            $imageHtml = str_replace('$2', $values->image_title, $imageHtml);
        } else if (isset($values->image_url)) {
            $imageHtml = str_replace('$1', $values->image_url, $imageTemplate);
        }

        $post = $imageHtml . $post;
        $lockedPost = $imageHtml . $lockedPost;

        $loader = new \Twig_Loader_Array([
            'html_template' => $sourceTemplate->content_html,
            'text_template' => $sourceTemplate->content_text,
        ]);
        $twig = new \Twig_Environment($loader);

        $text = str_replace("<br />", "\r\n", $post);
        $lockedText = str_replace("<br />", "\r\n", $lockedPost);

        $text = strip_tags($text);
        $lockedText = strip_tags($lockedText);

        $text = preg_replace('/(\r\n|\r|\n)+/', "\n", $text);
        $lockedText = preg_replace('/(\r\n|\r|\n)+/', "\n", $lockedText);

        $params = [
            'title' => $values->title,
            'sub_title' => $values->sub_title,
            'url' => $values->url,
            'html' => $post,
            'text' => $text,
        ];

        $lockedParams = [
            'title' => $values->title,
            'sub_title' => $values->sub_title,
            'url' => $values->url,
            'html' => $lockedPost,
            'text' => strip_tags($lockedText),
        ];


        $output = [];
        $output['htmlContent'] = $twig->render('html_template', $params);
        $output['textContent'] = strip_tags($twig->render('text_template', $params));
        $output['lockedHtmlContent'] = $twig->render('html_template', $lockedParams);
        $output['lockedTextContent'] = strip_tags($twig->render('text_template', $lockedParams));
        return $output;
    }

    public function formSucceeded($form, $values)
    {
        $output = $this->process($values);

        $addonParams = [
            'lockedHtmlContent' => $output['lockedHtmlContent'],
            'lockedTextContent' => $output['lockedTextContent'],
            'mmsTitle' => $values->title,
            'render' => true
        ];

        $this->onSubmit->__invoke($output['htmlContent'], $output['textContent'], $addonParams);
    }

    public function generateForm(Form $form)
    {
        // disable CSRF protection as external sources could post the params here
        $form->offsetUnset(Form::PROTECTOR_ID);

        $form->addText('title', 'Title')
            ->setRequired("Field 'Title' is required.");
        $form->offsetUnset(Form::PROTECTOR_ID);

        $form->addText('sub_title', 'Sub title');

        $form->addText('url', 'Odkaz MMS URL')
            ->addRule(Form::URL)
            ->setRequired("Field 'Odkaz MMS URL' is required.");

        $form->addTextArea('mms_html', 'HTML')
            ->setAttribute('rows', 20)
            ->setAttribute('class', 'form-control html-editor')
            ->getControlPrototype();

        $form->addSubmit('send')
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="fa fa-magic"></i> ' . 'Generate');

        $form->onSuccess[] = [$this, 'formSucceeded'];
    }

    public function onSubmit(callable $onSubmit)
    {
        $this->onSubmit = $onSubmit;
    }

    /**
     * @param $data object containing WP article data
     *
     * @return object with data to fill the form with
     * @throws \Remp\MailerModule\Api\v1\Handlers\Mailers\PreprocessException
     */
    public function preprocessParameters($data)
    {
        $output = new \stdClass();

        if (!isset($data->post_authors[0]->display_name)) {
            throw new PreprocessException("WP json object does not contain required attribute 'display_name' of first post author");
        }
        $output->from = "Denník N <info@dennikn.sk>";
        foreach ($data->post_authors as $author) {
            if ($author->user_email === "editori@dennikn.sk") {
                continue;
            }
            $output->from = $author->display_name . ' <' . $author->user_email . '>';
            break;
        }

        if (!isset($data->post_title)) {
            throw new PreprocessException("WP json object does not contain required attribute 'post_title'");
        }
        $output->title = $data->post_title;

        if (!isset($data->post_url)) {
            throw new PreprocessException("WP json object  does not contain required attribute 'post_url'");
        }
        $output->url = $data->post_url;

        if (!isset($data->post_excerpt)) {
            throw new PreprocessException("WP json object does not contain required attribute 'post_excerpt'");
        }
        $output->sub_title = $data->post_excerpt;

        if (isset($data->post_image->image_sizes->medium_large->file)) {
            $output->image_url = $data->post_image->image_sizes->medium_large->file;
        }

        if (isset($data->post_image->image_title)) {
            $output->image_title = $data->post_image->image_title;
        }

        if (!isset($data->post_content)) {
            throw new PreprocessException("WP json object does not contain required attribute 'post_content'");
        }
        $output->mms_html = $data->post_content;

        return $output;
    }

    public function getLockedHtml($html, $link)
    {
        $lockedHtml = '';
        $lock = null;

        list(
            $captionTemplate,
            $captionWithLinkTemplate,
            $liTemplate,
            $hrTemplate,
            $spacerTemplate
        ) = $this->getTemplates();

        if (stripos($html, '[lock newsletter]')) {
            $lock = '[lock newsletter]';
        } else if (stripos($html, '[lock]')) {
            $lock = '[lock]';
        }

        if (!is_null($lock)) {
            $parts = explode($lock, $html);

            $lockedHtml .= $parts[0];
            $lockedHtml .= $spacerTemplate . PHP_EOL . PHP_EOL;
            $lockedHtml .= "<p><a href=\"{$link}/{{ autologin }}\">Pokračovanie odkaz MMS - kliknite sem</a><p>";

            return $lockedHtml;
        }

        return $html;
    }

    public function parseEmbedd($matches)
    {
        $link = trim($matches[0]);

        if (preg_match('/youtu/', $link)) {
            $result = (new Client())->get('https://www.youtube.com/oembed?url=' . $link . '&format=json')->getBody()->getContents();
            $result = json_decode($result);
            $thumbLink = $result->thumbnail_url;

            return "<br><a href=\"{$link}\" target=\"_blank\" style=\"color:#181818;padding:0;margin:0;Margin:0;line-height:1.3;color:#5050f4;text-decoration:none;\"><img src=\"{$thumbLink}\" alt=\"\" style=\"outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;width:auto;max-width:100%;clear:both;display:block;margin-bottom:20px;\"></a><br><br>" . PHP_EOL;
        }

        return "<a href=\"{$link}\" target=\"_blank\" style=\"color:#181818;padding:0;margin:0;Margin:0;line-height:1.3;color:#5050f4;text-decoration:none;\">$link</a><br><br>";
    }

    public function getTemplates()
    {
        $captionTemplate = <<< HTML
    <img src="$1" alt="" style="outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;width:auto;max-width:100%;clear:both;display:block;margin-bottom:20px;">
    <p style="margin:0 0 0 26px;Margin:0 0 0 26px;color:#181818;padding:0;margin:0;Margin:0;line-height:1.3;font-size:18px;line-height:1.6;margin-bottom:26px;Margin-bottom:26px;line-height:160%;text-align:left;font-weight:normal;word-wrap:break-word;-webkit-hyphens:auto;-moz-hyphens:auto;hyphens:auto;border-collapse:collapse !important;">
        <small class="text-gray" style="font-size:13px;line-height:18px;display:block;color:#9B9B9B;">$2</small>
    </p>
HTML;

        $captionWithLinkTemplate = <<< HTML
    <a href="$1" style="color:#181818;font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;font-weight:normal;padding:0;margin:0;Margin:0;text-align:left;line-height:1.3;color:#5050f4;text-decoration:none;">
    <img src="$2" alt="" style="outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;width:auto;max-width:100%;clear:both;display:block;margin-bottom:20px;border:none;">
</a>
    <p style="margin:0 0 0 26px;Margin:0 0 0 26px;color:#181818;padding:0;margin:0;Margin:0;line-height:1.3;font-size:18px;line-height:1.6;margin-bottom:26px;Margin-bottom:26px;line-height:160%;text-align:left;font-weight:normal;word-wrap:break-word;-webkit-hyphens:auto;-moz-hyphens:auto;hyphens:auto;border-collapse:collapse !important;">
        <small class="text-gray" style="font-size:13px;line-height:18px;display:block;color:#9B9B9B;">$3</small>
    </p>
HTML;

        $liTemplate = <<< HTML
    <tr style="padding:0;vertical-align:top;text-align:left;">
        <td class="bullet" style="padding:0;vertical-align:top;text-align:left;font-size:18px;line-height:1.6;width:30px;border-collapse:collapse !important;">&#8226;</td>
        <td style="padding:0;vertical-align:top;text-align:left;font-size:18px;line-height:1.6;border-collapse:collapse !important;">
            <p style="margin:0 0 0 26px;Margin:0 0 0 26px;color:#181818;padding:0;margin:0;Margin:0;line-height:1.3;font-size:18px;line-height:1.6;margin-bottom:26px;Margin-bottom:26px;line-height:160%;text-align:left;font-weight:normal;word-wrap:break-word;-webkit-hyphens:auto;-moz-hyphens:auto;hyphens:auto;border-collapse:collapse !important;">$1</p>
        </td>
    </tr>
HTML;

        $hrTemplate = <<< HTML
    <table cellspacing="0" cellpadding="0" border="0" width="100%" style="border-spacing:0;border-collapse:collapse;vertical-align:top;color:#181818;padding:0;margin:0;Margin:0;line-height:1.3;text-align:left;font-family:'Helvetica Neue', Helvetica, Arial;width:100%;">
        <tr style="padding:0;vertical-align:top;text-align:left;">
            <td style="padding:0;vertical-align:top;text-align:left;font-size:18px;line-height:1.6;border-collapse:collapse !important; padding: 30px 0 0 0; border-top:1px solid #E2E2E2;"></td>
        </tr>
    </table>

HTML;

        $spacerTemplate = <<< HTML
        <table class="spacer" style="border-spacing:0;border-collapse:collapse;vertical-align:top;color:#181818;padding:0;margin:0;Margin:0;line-height:1.3;text-align:left;font-family:'Helvetica Neue', Helvetica, Arial;width:100%;">
            <tbody>
                <tr style="padding:0;vertical-align:top;text-align:left;">
                    <td height="20px" style="padding:0;vertical-align:top;text-align:left;font-size:18px;line-height:1.6;mso-line-height-rule:exactly;border-collapse:collapse !important;font-size:20px;line-height:20px;">&#xA0;</td>
                </tr>
            </tbody>
        </table>
HTML;

        $imageTemplate = <<< HTML
        <img src="$1" alt="" style="outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;width:auto;max-width:100%;clear:both;display:block;margin-bottom:20px;">
HTML;

        return [
            $captionTemplate,
            $captionWithLinkTemplate,
            $liTemplate,
            $hrTemplate,
            $spacerTemplate,
            $imageTemplate,
        ];
    }
}
