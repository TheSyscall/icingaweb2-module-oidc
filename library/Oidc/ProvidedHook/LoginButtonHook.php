<?php

namespace Icinga\Module\Oidc\ProvidedHook;

use Icinga\Application\Hook\LoginButtonHook as BaseLoginButtonHook;
use Icinga\Application\Icinga;
use Icinga\Application\Logger;
use Icinga\Application\Modules\Module;
use Icinga\Authentication\LoginButton;
use Icinga\Module\Oidc\Common\Database;
use Icinga\Module\Oidc\FileHelper;
use Icinga\Module\Oidc\Model\Provider;
use Icinga\Web\Url;
use ipl\Html\Attributes;
use ipl\Html\HtmlDocument;
use ipl\Html\Html;
use ipl\Html\Text;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\StyleWithNonce;

class LoginButtonHook extends BaseLoginButtonHook
{
    public function getButtons(): array
    {
        $buttons = [];

        try {
            $providers = Provider::on(Database::get())->filter(Filter::equal('enabled', 'y'));

            foreach ($providers as $provider) {
                $buttons[] = new LoginButton(
                    function () use ($provider): void {
                        $basePath = str_replace('//', '/', Icinga::app()->getRequest()->getBasePath() . '/');
                        if (! empty($_GET['redirect'])) {
                            setcookie('oidc-redirect', $_GET['redirect'], time() + 300, $basePath);
                        } else {
                            setcookie('oidc-redirect', '', time() - 3600, $basePath);
                        }

                        $response = Icinga::app()->getResponse();
                        $response->setHeader('X-Icinga-Redirect-Http', 'yes');
                        $response->redirectAndExit(
                            Url::fromPath('oidc/authentication/realm', ['name' => $provider->name])
                        );
                    },
                    $this->buildButtonContent($provider),
                    // CSS class targeted by the StyleWithNonce in buildButtonContent().
                    // A style="" attribute cannot be used here: icingaweb2's CSP blocks inline style attributes.
                    Attributes::create(['class' => 'oidc-btn-' . $provider->id])
                );
            }
        } catch (\Throwable $e) {
            Logger::error('OIDC: Failed to load providers for login buttons: %s', $e->getMessage());
        }

        return $buttons;
    }

    protected function buildButtonContent(Provider $provider): HtmlDocument
    {
        $doc = new HtmlDocument();

        // Deliver colors via a nonce-bearing <style> block.
        // style="" attributes are blocked by icingaweb2's CSP; StyleWithNonce emits a compliant <style nonce="...">.
        // The selector #login button.oidc-btn-X (specificity 0,1,1,1) beats the base rule #login button (0,1,0,1).
        $doc->addHtml(
            (new StyleWithNonce())->add(
                '#login button.oidc-btn-' . $provider->id,
                [
                    'background-color' => $provider->buttoncolor,
                    'color'            => $provider->textcolor,
                ]
            )
        );

        try {
            $fileHelper = new FileHelper(
                Module::get('oidc')->getConfigDir() . DIRECTORY_SEPARATOR . 'files'
            );
            $file = $fileHelper->getFile($provider->logo);

            if ($file !== false) {
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                if ($extension === 'svg') {
                    $extension .= '+xml';
                }
                $imgSrc = 'data:image/' . $extension . ';base64,' . base64_encode(
                    file_get_contents($file['realPath'])
                );
                $doc->addHtml(Html::tag('img', ['class' => 'logo-size', 'src' => $imgSrc, 'alt' => '']));
            }
        } catch (\Throwable $e) {
            Logger::error('OIDC: Failed to load provider logo: %s', $e->getMessage());
        }

        $doc->addHtml(new Text($provider->caption));

        return $doc;
    }
}
