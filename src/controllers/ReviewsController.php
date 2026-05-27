<?php

namespace bymayo\vouch\controllers;

use bymayo\vouch\connectors\manual\ManualConnector;
use bymayo\vouch\elements\Review;
use bymayo\vouch\models\Source;
use bymayo\vouch\Vouch;
use Craft;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ReviewsController extends Controller
{
    /**
     * Only `submit` is anonymous - it's the public front-end form action.
     * Everything else requires `vouch-viewReviews` (gated in beforeAction).
     */
    protected array|int|bool $allowAnonymous = ['submit'];

    public function beforeAction($action): bool
    {
        if ($action->id !== 'submit') {
            $this->requirePermission('vouch-viewReviews');
        }
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('vouch/reviews/index');
    }

    public function actionEdit(?int $reviewId = null, ?Review $review = null): Response
    {
        $vouch = Vouch::getInstance();

        if (!$review) {
            if ($reviewId) {
                $review = Review::find()->id($reviewId)->status(null)->one();
                if (!$review) {
                    throw new NotFoundHttpException();
                }
            } else {
                // New review: we can only create against a Manual source.
                // If none exist, send the user to create one - the empty
                // form would have no source to attach to.
                $manualSources = $vouch->sources->getSourcesByProvider(ManualConnector::handle());
                if (empty($manualSources)) {
                    Craft::$app->getSession()->setNotice(
                        Craft::t('vouch', 'Create a Manual source first to author reviews in the CP.'),
                    );
                    return $this->redirect('vouch/sources/new/' . ManualConnector::handle());
                }

                $source = $manualSources[0];
                $review = $vouch->reviews->newManualReview($source);
            }
        }

        $manualSources = $vouch->sources->getSourcesByProvider(ManualConnector::handle());
        $isManual = $review->getSource()?->providerHandle === ManualConnector::handle();

        // Resolve the related element + its concrete class here so the Twig
        // template doesn't need a `|class` filter (which doesn't exist).
        $relatedElement = $review->relatedElementId
            ? Craft::$app->getElements()->getElementById($review->relatedElementId)
            : null;
        $relatedElementType = $relatedElement
            ? get_class($relatedElement)
            : \craft\elements\Entry::class;

        return $this->renderTemplate('vouch/reviews/_edit', [
            'review' => $review,
            'manualSources' => $manualSources,
            'isManual' => $isManual,
            'relatedElement' => $relatedElement,
            'relatedElementType' => $relatedElementType,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $reviewId = $request->getBodyParam('reviewId');

        if ($reviewId) {
            $this->requirePermission('vouch-editReviews');
            $review = Review::find()->id((int) $reviewId)->status(null)->one();
            if (!$review) {
                throw new NotFoundHttpException();
            }
        } else {
            $this->requirePermission('vouch-createReviews');
            $sourceId = (int) $request->getRequiredBodyParam('sourceId');
            $source = Vouch::getInstance()->sources->getSourceById($sourceId);
            if (!$source) {
                throw new BadRequestHttpException('Unknown source.');
            }
            $review = Vouch::getInstance()->reviews->newManualReview($source);
        }

        $this->populateFromRequest($review, $request);

        if (!Vouch::getInstance()->reviews->save($review)) {
            Craft::$app->getSession()->setError(Craft::t('vouch', 'Couldn’t save review.'));
            Craft::$app->getUrlManager()->setRouteParams(['review' => $review]);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('vouch', 'Review saved.'));
        return $this->redirectToPostedUrl($review);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('vouch-deleteReviews');

        $reviewId = (int) Craft::$app->getRequest()->getRequiredBodyParam('reviewId');
        $review = Review::find()->id($reviewId)->status(null)->one();
        if (!$review) {
            throw new NotFoundHttpException();
        }

        Vouch::getInstance()->reviews->delete($review);

        Craft::$app->getSession()->setNotice(Craft::t('vouch', 'Review deleted.'));
        return $this->redirect('vouch/reviews');
    }

    public function actionApprove(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('vouch-approveReviews');

        $reviewId = (int) Craft::$app->getRequest()->getRequiredBodyParam('reviewId');
        $review = Review::find()->id($reviewId)->status(null)->one();
        if (!$review) {
            throw new NotFoundHttpException();
        }

        Vouch::getInstance()->reviews->approve($review);

        Craft::$app->getSession()->setNotice(Craft::t('vouch', 'Review approved.'));
        return $this->redirectToPostedUrl($review);
    }

    /**
     * Public, anonymous-allowed action for front-end review forms.
     * Reviews are only accepted against a Manual source - API-backed sources
     * have their reviews flow from the provider, not user submissions.
     *
     * Example Twig:
     *
     *   <form method="post">
     *     {{ csrfInput() }}
     *     <input type="hidden" name="action" value="vouch/reviews/submit">
     *     <input type="hidden" name="sourceHandle" value="customer-reviews">
     *     <input type="number" name="rating" min="1" max="5" required>
     *     <textarea name="review" required></textarea>
     *     <input name="reviewerName" required>
     *     <input name="reviewerEmail" type="email">
     *     <button type="submit">Submit</button>
     *   </form>
     */
    public function actionSubmit(): ?Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $vouch = Vouch::getInstance();
        $settings = $vouch->getSettings();

        $sourceHandle = (string) $request->getBodyParam('sourceHandle', '');
        $source = $sourceHandle ? $vouch->sources->getSourceByHandle($sourceHandle) : null;
        if (!$source) {
            throw new BadRequestHttpException('Unknown source handle.');
        }
        // Hard-stop: front-end submissions can only feed Manual sources.
        // Allowing them to write into a Trustpilot/Feefo source would let
        // the public bypass the provider's own moderation entirely.
        if ($source->providerHandle !== ManualConnector::handle()) {
            throw new BadRequestHttpException('Front-end submissions are only allowed for Manual sources.');
        }

        // Honeypot: a hidden `vouchHoneypot` field that real users never see
        // and bots tend to fill in. Anything non-empty is treated as spam.
        // We respond with a fake success so the bot doesn't learn it's been
        // caught and try a different approach.
        if (trim((string) $request->getBodyParam('vouchHoneypot', '')) !== '') {
            if ($request->getAcceptsJson()) {
                return $this->asJson(['ok' => true]);
            }
            return $this->redirectToPostedUrl();
        }

        // Per-IP rate limiting using Craft's cache - a sliding window of
        // submission timestamps. Old entries fall out as the window moves.
        if ($settings->submissionRateLimit > 0 && $settings->submissionRateWindow > 0) {
            $ip = (string) $request->getUserIP();
            if ($ip !== '') {
                $cache = Craft::$app->getCache();
                $key = 'vouch.submitRate.' . hash('sha256', $ip);
                $now = time();
                $cutoff = $now - $settings->submissionRateWindow;
                $timestamps = array_filter((array) ($cache->get($key) ?: []), fn($t) => $t > $cutoff);

                if (count($timestamps) >= $settings->submissionRateLimit) {
                    $message = Craft::t('vouch', 'Too many submissions - please try again in a moment.');
                    if ($request->getAcceptsJson()) {
                        return $this->asJson(['ok' => false, 'message' => $message])
                            ->setStatusCode(429);
                    }
                    Craft::$app->getSession()->setError($message);
                    return null;
                }

                $timestamps[] = $now;
                $cache->set($key, $timestamps, $settings->submissionRateWindow);
            }
        }

        $review = $vouch->reviews->newManualReview($source);

        $review->rating = (float) $request->getBodyParam('rating', 0);
        $review->headline = $request->getBodyParam('headline');
        $review->review = $request->getBodyParam('review');
        $review->reviewerName = $request->getBodyParam('reviewerName');
        $review->reviewerEmail = $request->getBodyParam('reviewerEmail');

        $currentUser = Craft::$app->getUser()->getIdentity();
        $submittedEmail = $review->reviewerEmail ? trim($review->reviewerEmail) : null;

        // When `requireLoginForKnownEmails` is on, reject any submission whose
        // email belongs to an existing Craft user unless the submitter is
        // currently logged in as that user. Stops the spam-by-real-email
        // attack where the attacker isn't trying to forge attribution, just
        // to plant a victim's email on a public review.
        if ($submittedEmail && Vouch::getInstance()->getSettings()->requireLoginForKnownEmails) {
            $existingUser = Craft::$app->getUsers()->getUserByUsernameOrEmail($submittedEmail);
            if ($existingUser && (!$currentUser || (int) $currentUser->id !== (int) $existingUser->id)) {
                $loginUrl = \craft\helpers\UrlHelper::siteUrl(Craft::$app->getConfig()->getGeneral()->getLoginPath());
                $message = Craft::t('vouch', 'That email belongs to a registered account. Please log in to leave a review.');

                // Attach as a validation error on the field so Twig templates that
                // already render `review.getErrors('reviewerEmail')` will surface
                // it without any extra work. Flash error covers form templates
                // that rely on session messages.
                $review->addError('reviewerEmail', $message);

                if ($request->getAcceptsJson()) {
                    return $this->asJson([
                        'ok' => false,
                        'requiresLogin' => true,
                        'loginUrl' => $loginUrl,
                        'message' => $message,
                        'errors' => $review->getErrors(),
                    ])->setStatusCode(403);
                }

                Craft::$app->getSession()->setError($message);
                Craft::$app->getUrlManager()->setRouteParams([
                    'review' => $review,
                    'requiresLogin' => true,
                    'loginUrl' => $loginUrl,
                ]);
                return null;
            }
        }

        // Attribution: only link the review to a Craft user when the
        // submitter is currently logged in AND the email they submitted
        // matches their own account. This blocks the forge-by-email attack
        // (anonymous user submits with someone else's email to attribute
        // the review to them).
        if ($currentUser
            && $submittedEmail
            && strcasecmp($currentUser->email, $submittedEmail) === 0
        ) {
            $review->reviewerUserId = $currentUser->id;
        }

        $relatedId = $request->getBodyParam('relatedElementId');
        if ($relatedId) {
            $review->relatedElementId = (int) $relatedId;
        }

        if (!$vouch->reviews->save($review)) {
            if ($request->getAcceptsJson()) {
                return $this->asJson(['ok' => false, 'errors' => $review->getErrors()]);
            }
            Craft::$app->getUrlManager()->setRouteParams(['review' => $review]);
            return null;
        }

        if ($request->getAcceptsJson()) {
            return $this->asJson([
                'ok' => true,
                'id' => $review->id,
                'approved' => $review->approved,
            ]);
        }

        return $this->redirectToPostedUrl($review);
    }

    /**
     * Map request body fields onto the review element. Used by `actionSave`
     * for both new + edit. Re-syncing an API review will overwrite anything
     * edited here - the form warns the user about that.
     */
    private function populateFromRequest(Review $review, \craft\web\Request $request): void
    {
        if (!$review->sourceId) {
            $sourceId = $request->getBodyParam('sourceId');
            if ($sourceId) {
                $review->sourceId = (int) $sourceId;
            }
        }

        $review->rating = (float) $request->getBodyParam('rating', $review->rating);
        $review->headline = $request->getBodyParam('headline', $review->headline);
        $review->review = $request->getBodyParam('review', $review->review);
        $review->reviewerName = $request->getBodyParam('reviewerName', $review->reviewerName);
        $review->reviewerEmail = $request->getBodyParam('reviewerEmail', $review->reviewerEmail);
        $review->businessReply = $request->getBodyParam('businessReply', $review->businessReply);
        $review->approved = (bool) $request->getBodyParam('approved', $review->approved);

        $reviewedAt = $request->getBodyParam('reviewedAt');
        if (is_array($reviewedAt)) {
            $review->reviewedAt = \craft\helpers\DateTimeHelper::toDateTime($reviewedAt) ?: $review->reviewedAt;
        }

        $relatedId = $request->getBodyParam('relatedElementId');
        if (is_array($relatedId)) {
            $relatedId = reset($relatedId) ?: null;
        }
        $review->relatedElementId = $relatedId ? (int) $relatedId : null;

        $reviewerUserId = $request->getBodyParam('reviewerUserId');
        if (is_array($reviewerUserId)) {
            $reviewerUserId = reset($reviewerUserId) ?: null;
        }
        $review->reviewerUserId = $reviewerUserId ? (int) $reviewerUserId : null;
    }
}
