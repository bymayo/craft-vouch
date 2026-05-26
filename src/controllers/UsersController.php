<?php

namespace bymayo\vouch\controllers;

use bymayo\vouch\elements\Review;
use Craft;
use craft\controllers\EditUserTrait;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\web\Controller;
use craft\web\CpScreenResponseBehavior;
use yii\web\Response;

/**
 * Renders the Vouch screen on the user edit page (and `/myaccount`) - an
 * embedded reviews element index filtered to the chosen user. Mirrors the
 * pattern Commerce's `craft\commerce\controllers\UsersController` uses for
 * its "Commerce" tab.
 */
class UsersController extends Controller
{
    use EditUserTrait;

    public const SCREEN_REVIEWS = 'vouch-reviews';

    public function actionIndex(?int $userId = null): Response
    {
        $user = $this->editedUser($userId);
        $this->requirePermission('vouch-viewReviews');

        /** @var Response|CpScreenResponseBehavior $response */
        $response = $this->asEditUserScreen($user, self::SCREEN_REVIEWS);

        $content = Html::tag('h2', Craft::t('vouch', 'Reviews by {name}', ['name' => $user->getUiLabel()]))
            . Cp::elementIndexHtml(Review::class, [
                'id' => sprintf('vouch-user-reviews-%s', mt_rand()),
                'context' => 'embedded-index',
                'sources' => false,
                'showSiteMenu' => false,
                'jsSettings' => [
                    'criteria' => [
                        'reviewerUserId' => $user->id,
                        'status' => null,
                    ],
                ],
            ]);

        return $response->contentHtml($content);
    }
}
