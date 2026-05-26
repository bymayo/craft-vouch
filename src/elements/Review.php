<?php

namespace bymayo\vouch\elements;

use bymayo\vouch\elements\conditions\ReviewCondition;
use bymayo\vouch\elements\db\ReviewQuery;
use bymayo\vouch\models\Source;
use bymayo\vouch\records\ReviewRecord;
use bymayo\vouch\Vouch;
use Craft;
use craft\base\Element;
use craft\elements\conditions\ElementConditionInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\UrlHelper;

/**
 * A normalised review from any provider. Reviews are not user-content -
 * end-users in the CP don't author them - so this element has no Title field,
 * no URL, and no localisation. Statuses model approval state:
 * `live` = approved & visible; `pending` = awaiting moderation.
 */
class Review extends Element
{
    public const STATUS_LIVE = 'live';
    public const STATUS_PENDING = 'pending';

    public ?int $sourceId = null;
    public ?string $externalId = null;
    public float $rating = 0.0;
    public ?string $headline = null;
    public ?string $review = null;
    public ?string $reviewerName = null;
    public ?string $reviewerEmail = null;
    public ?string $reviewerEmailHash = null;
    public ?int $reviewerUserId = null;
    public ?int $relatedElementId = null;
    public ?\DateTime $reviewedAt = null;
    public ?string $businessReply = null;
    public ?string $raw = null;
    public bool $approved = true;

    private ?Source $_source = null;
    private ?User $_user = null;

    public static function displayName(): string
    {
        return Craft::t('vouch', 'Review');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('vouch', 'review');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('vouch', 'Reviews');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('vouch', 'reviews');
    }

    public static function refHandle(): ?string
    {
        return 'review';
    }

    public static function hasContent(): bool
    {
        return false;
    }

    public static function hasTitles(): bool
    {
        return false;
    }

    public static function hasUris(): bool
    {
        return false;
    }

    public static function isLocalized(): bool
    {
        return false;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_LIVE => Craft::t('vouch', 'Live'),
            self::STATUS_PENDING => Craft::t('vouch', 'Pending Approval'),
        ];
    }

    public function getStatus(): ?string
    {
        return $this->approved ? self::STATUS_LIVE : self::STATUS_PENDING;
    }

    public static function find(): ElementQueryInterface
    {
        return new ReviewQuery(static::class);
    }

    public static function createCondition(): ElementConditionInterface
    {
        return Craft::createObject(ReviewCondition::class, [static::class]);
    }

    protected static function defineActions(?string $source = null): array
    {
        $actions = parent::defineActions($source);

        // Only surface the bulk approve when the user has permission and we're
        // looking at a source that's likely to contain pending rows. The action
        // itself is a no-op for already-approved reviews, but offering it on the
        // "All reviews" / per-source views keeps the UI usable in moderation.
        if (Craft::$app->getUser()->checkPermission('vouch-editReviews')) {
            $actions[] = \bymayo\vouch\elements\actions\ApproveReviews::class;
        }

        return $actions;
    }

    protected static function defineSources(?string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('vouch', 'All reviews'),
                'criteria' => [],
                'defaultSort' => ['reviewedAt', 'desc'],
            ],
            [
                'key' => 'pending',
                'label' => Craft::t('vouch', 'Pending Approval'),
                'criteria' => ['approved' => false],
                'defaultSort' => ['reviewedAt', 'desc'],
            ],
        ];

        $configured = Vouch::getInstance()->sources->getAllSources();
        if (!empty($configured)) {
            $sources[] = ['heading' => Craft::t('vouch', 'Sources')];
            foreach ($configured as $source) {
                $sources[] = [
                    'key' => 'source:' . $source->id,
                    'label' => $source->name,
                    'criteria' => ['sourceId' => $source->id],
                    'defaultSort' => ['reviewedAt', 'desc'],
                ];
            }
        }

        return $sources;
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'rating' => ['label' => Craft::t('vouch', 'Rating')],
            'reviewerName' => ['label' => Craft::t('vouch', 'Reviewer')],
            'source' => ['label' => Craft::t('vouch', 'Source')],
            'relatedElement' => ['label' => Craft::t('vouch', 'Related element')],
            'reviewedAt' => ['label' => Craft::t('vouch', 'Reviewed')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['rating', 'reviewerName', 'source', 'relatedElement', 'reviewedAt'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'reviewedAt' => [
                'label' => Craft::t('vouch', 'Reviewed'),
                'orderBy' => 'vouch_reviews.reviewedAt',
                'attribute' => 'reviewedAt',
            ],
            'rating' => [
                'label' => Craft::t('vouch', 'Rating'),
                'orderBy' => 'vouch_reviews.rating',
                'attribute' => 'rating',
            ],
            'dateCreated' => [
                'label' => Craft::t('app', 'Date Created'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
            ],
        ];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['reviewerName', 'headline', 'review'];
    }

    public function getSource(): ?Source
    {
        if ($this->_source === null && $this->sourceId) {
            $this->_source = Vouch::getInstance()->sources->getSourceById($this->sourceId);
        }
        return $this->_source;
    }

    /** Convenience for Twig: `review.sourceName`. */
    public function getSourceName(): ?string
    {
        return $this->getSource()?->name;
    }

    /** Convenience for Twig: `review.sourceHandle`. */
    public function getSourceHandle(): ?string
    {
        return $this->getSource()?->handle;
    }

    /** Convenience for Twig: `review.providerHandle` (google, trustpilot, ...). */
    public function getProviderHandle(): ?string
    {
        return $this->getSource()?->providerHandle;
    }

    public function getReviewerUser(): ?User
    {
        if ($this->_user === null && $this->reviewerUserId) {
            $this->_user = Craft::$app->getUsers()->getUserById($this->reviewerUserId);
        }
        return $this->_user;
    }

    public function canView(User $user): bool
    {
        return $user->can('vouch-viewReviews');
    }

    public function canSave(User $user): bool
    {
        return $user->can($this->id ? 'vouch-editReviews' : 'vouch-createReviews');
    }

    public function canDelete(User $user): bool
    {
        return $user->can('vouch-deleteReviews');
    }

    public function getCpEditUrl(): ?string
    {
        return $this->id ? UrlHelper::cpUrl('vouch/reviews/' . $this->id) : null;
    }

    public function getUiLabel(): string
    {
        if ($this->headline) {
            return $this->headline;
        }
        // Google reviews don't carry a headline - fall back to a review snippet
        // so the row reads as the actual review ("I loved this product")
        // rather than an opaque "Review #2082".
        if ($this->review) {
            $snippet = trim((string) $this->review);
            if (mb_strlen($snippet) > 80) {
                $snippet = mb_substr($snippet, 0, 80) . '…';
            }
            return $snippet;
        }
        if ($this->reviewerName) {
            return $this->reviewerName;
        }
        return Craft::t('vouch', 'Review #{id}', ['id' => $this->id ?? '?']);
    }

    public function __toString(): string
    {
        return $this->getUiLabel();
    }

    protected function attributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'rating':
                return $this->renderStars($this->rating);
            case 'reviewerName':
                $user = $this->getReviewerUser();
                if ($user) {
                    return Cp::elementChipHtml($user);
                }
                return Html::encode((string)$this->reviewerName);
            case 'source':
                $source = $this->getSource();
                return $source
                    ? Html::a(Html::encode($source->name), UrlHelper::cpUrl('vouch/sources/' . $source->id))
                    : '';
            case 'relatedElement':
                if (!$this->relatedElementId) {
                    return '';
                }
                $related = Craft::$app->getElements()->getElementById($this->relatedElementId);
                return $related ? Cp::elementChipHtml($related) : '';
            case 'reviewedAt':
                if (!$this->reviewedAt) {
                    return '';
                }
                return Craft::$app->getFormatter()->asDatetime($this->reviewedAt, 'short');
        }

        return parent::attributeHtml($attribute);
    }

    /**
     * Inline ★/☆ rendering. Avoids dragging in an icon font for one column.
     */
    private function renderStars(float $rating): string
    {
        $full = (int) floor($rating);
        $half = ($rating - $full) >= 0.5 ? 1 : 0;
        $empty = 5 - $full - $half;
        $stars = str_repeat('★', $full) . ($half ? '⯨' : '') . str_repeat('☆', $empty);
        return sprintf(
            '<span title="%s" style="letter-spacing:1px;">%s</span>',
            Html::encode(number_format($rating, 1)),
            $stars,
        );
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['sourceId', 'externalId', 'rating'], 'required'];
        $rules[] = [['sourceId', 'reviewerUserId', 'relatedElementId'], 'integer'];
        $rules[] = [['rating'], 'number', 'min' => 0, 'max' => 5];
        $rules[] = [['approved'], 'boolean'];
        $rules[] = [['externalId'], 'string', 'max' => 255];

        // Length caps from plugin settings - applied to every save path.
        // `max=0` disables the limit. Synced reviews are exempt by the same
        // logic as the required-fields rule below: providers control payload
        // length and we don't want to reject their data after the fact.
        $settings = Vouch::getInstance()->getSettings();
        $manualOnly = function($model) {
            $source = $model->getSource();
            return $source !== null && $source->providerHandle === 'manual';
        };
        if ($settings->headlineMaxLength > 0) {
            $rules[] = [['headline'], 'string', 'max' => $settings->headlineMaxLength, 'when' => $manualOnly];
        }
        if ($settings->reviewMaxLength > 0) {
            $rules[] = [['review'], 'string', 'max' => $settings->reviewMaxLength, 'when' => $manualOnly];
        }

        // Manual reviews require these fields too. Synced reviews are
        // exempt - providers don't always supply headline/email/etc. and we
        // don't want a strict server-side check to reject API payloads.
        $rules[] = [
            ['headline', 'review', 'reviewerName', 'reviewerEmail', 'reviewedAt'],
            'required',
            'when' => $manualOnly,
        ];

        return $rules;
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $data = [
                'sourceId' => $this->sourceId,
                'externalId' => $this->externalId,
                'rating' => $this->rating,
                'headline' => $this->headline,
                'review' => $this->review,
                'reviewerName' => $this->reviewerName,
                'reviewerEmail' => $this->reviewerEmail,
                'reviewerEmailHash' => $this->reviewerEmailHash,
                'reviewerUserId' => $this->reviewerUserId,
                'relatedElementId' => $this->relatedElementId,
                'reviewedAt' => Db::prepareDateForDb($this->reviewedAt),
                'businessReply' => $this->businessReply,
                'raw' => $this->raw,
                'approved' => $this->approved,
            ];

            if ($isNew) {
                Craft::$app->getDb()->createCommand()
                    ->insert(ReviewRecord::tableName(), $data + ['id' => $this->id])
                    ->execute();
            } else {
                Craft::$app->getDb()->createCommand()
                    ->update(ReviewRecord::tableName(), $data, ['id' => $this->id])
                    ->execute();
            }
        }

        parent::afterSave($isNew);
    }
}
