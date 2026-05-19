<?php

namespace bymayo\vouch\controllers;

use bymayo\vouch\elements\Review;
use bymayo\vouch\Vouch;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ReviewsController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission('vouch-viewReviews');
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('vouch/reviews/index');
    }

    public function actionEdit(int $reviewId): Response
    {
        $review = Review::find()->id($reviewId)->status(null)->one();
        if (!$review) {
            throw new NotFoundHttpException();
        }

        return $this->renderTemplate('vouch/reviews/_edit', [
            'review' => $review,
        ]);
    }

    public function actionApprove(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('vouch-editReviews');

        $reviewId = (int) Craft::$app->getRequest()->getRequiredBodyParam('reviewId');
        $review = Review::find()->id($reviewId)->status(null)->one();
        if (!$review) {
            throw new NotFoundHttpException();
        }

        Vouch::getInstance()->reviews->approve($review);

        Craft::$app->getSession()->setNotice(Craft::t('vouch', 'Review approved.'));
        return $this->redirectToPostedUrl($review);
    }
}
