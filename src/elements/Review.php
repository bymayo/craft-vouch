<?php

namespace bymayo\vouch\elements;

use bymayo\vouch\elements\db\ReviewQuery;
use bymayo\vouch\models\Source;
use bymayo\vouch\records\ReviewRecord;
use bymayo\vouch\Vouch;
use Craft;
use craft\base\Element;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\UrlHelper;

/**
 * A normalised review from any provider. Reviews are not user-content —
 * end-users in the CP don't author them — so this element has no Title field,
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
    public ?string $title = null;
    public ?string $body = null;
    public ?string $authorName = null;
    public ?string $authorEmail = null;
    public ?string $authorEmailHash = null;
    public ?int $authorUserId = null;
    public ?int $relatedElementId = null;
    public ?\DateTime $reviewedAt = null;
    public ?string $response = null;
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
            self::STATUS_PENDING => Craft::t('vouch', 'Pending approval'),
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
                'label' => Craft::t('vouch', 'Pending approval'),
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
            'authorName' => ['label' => Craft::t('vouch', 'Author')],
            'source' => ['label' => Craft::t('vouch', 'Source')],
            'reviewedAt' => ['label' => Craft::t('vouch', 'Reviewed')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['rating', 'authorName', 'source', 'reviewedAt'];
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
        return ['authorName', 'title', 'body'];
    }

    public function getSource(): ?Source
    {
        if ($this->_source === null && $this->sourceId) {
            $this->_source = Vouch::getInstance()->sources->getSourceById($this->sourceId);
        }
        return $this->_source;
    }

    public function getAuthorUser(): ?User
    {
        if ($this->_user === null && $this->authorUserId) {
            $this->_user = Craft::$app->getUsers()->getUserById($this->authorUserId);
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
        if ($this->title) {
            return $this->title;
        }
        if ($this->authorName) {
            return Craft::t('vouch', '{author} ({rating}★)', [
                'author' => $this->authorName,
                'rating' => number_format($this->rating, 1),
            ]);
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
            case 'authorName':
                $user = $this->getAuthorUser();
                if ($user) {
                    return Cp::elementChipHtml($user);
                }
                return Html::encode((string)$this->authorName);
            case 'source':
                $source = $this->getSource();
                return $source
                    ? Html::a(Html::encode($source->name), UrlHelper::cpUrl('vouch/sources/' . $source->id))
                    : '';
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
        $rules[] = [['sourceId', 'authorUserId', 'relatedElementId'], 'integer'];
        $rules[] = [['rating'], 'number', 'min' => 0, 'max' => 5];
        $rules[] = [['approved'], 'boolean'];
        $rules[] = [['externalId'], 'string', 'max' => 255];
        return $rules;
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $data = [
                'sourceId' => $this->sourceId,
                'externalId' => $this->externalId,
                'rating' => $this->rating,
                'title' => $this->title,
                'body' => $this->body,
                'authorName' => $this->authorName,
                'authorEmail' => $this->authorEmail,
                'authorEmailHash' => $this->authorEmailHash,
                'authorUserId' => $this->authorUserId,
                'relatedElementId' => $this->relatedElementId,
                'reviewedAt' => $this->reviewedAt
                    ? $this->reviewedAt->format('Y-m-d H:i:s')
                    : null,
                'response' => $this->response,
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
