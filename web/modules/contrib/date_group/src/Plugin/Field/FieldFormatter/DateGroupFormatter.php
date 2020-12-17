<?php

namespace Drupal\date_group\Plugin\Field\FieldFormatter;

use Drupal\Component\Datetime\DateTimePlus;
use Drupal\Core\Datetime\DateFormatInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\datetime_range\Plugin\Field\FieldFormatter\DateRangeDefaultFormatter;

/**
 * Plugin implementation of the 'date_group' formatter for 'daterange' fields.
 *
 * @FieldFormatter(
 *   id = "date_group",
 *   label = @Translation("Date group"),
 *   field_types = {
 *     "daterange"
 *   }
 * )
 */
class DateGroupFormatter extends DateRangeDefaultFormatter {

  /**
   * All possible formats used in PHP to display a date.
   *
   * @var string[][]
   */
  private $allDateFormats = [
    'time' => ['g', 'G', 'h', 'H', 'i', 's'],
    'day' => ['d', 'D', 'j', 'l', 'N'],
    'numericDay' => ['d', 'j'],
    'ordinalSuffix' => ['.', 'S'],
    'month' => ['F', 'm', 'M', 'n', 't'],
    'year' => ['o', 'Y', 'y'],
  ];

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'time_separator' => ':',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form = parent::settingsForm($form, $form_state);

    $form['time_separator'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Time separator'),
      '#description' => $this->t('The string to separate the start and end time'),
      '#default_value' => $this->getSetting('time_separator'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $time_zone = date_default_timezone_get();
    $time_zone = new \DateTimeZone($time_zone);

    foreach ($items as $delta => $item) {
      if (empty($item->end_date)) {
        $item->end_date = $item->start_date;
      }
      if (!empty($item->start_date) && !empty($item->end_date)) {
        /** @var \Drupal\Core\Datetime\DrupalDateTime $start_date */
        $start_date = $item->start_date;
        $start_date->setTimezone($time_zone);
        /** @var \Drupal\Core\Datetime\DrupalDateTime $end_date */
        $end_date = $item->end_date;
        $end_date->setTimezone($time_zone);

        if ($start_date->getTimestamp() !== $end_date->getTimestamp()) {
          $group_dates = $this->groupDates($start_date, $end_date);

          $elements[$delta] = [
            '#markup' => $group_dates,
            '#cache' => [
              'contexts' => [
                'timezone',
              ],
            ],
          ];
        }
        else {
          $elements[$delta] = $this->buildDate($item->start_date);

          if (!empty($item->_attributes)) {
            $elements[$delta]['#attributes'] += $item->_attributes;
            // Unset field item attributes since they have been included in the
            // formatter output and should not be rendered in field template.
            unset($item->_attributes);
          }
        }
      }
    }

    return $elements;
  }

  /**
   * Group start and end dates together by merging common date parts.
   *
   * @param \Drupal\Component\Datetime\DateTimePlus $start_date
   *   Start date timestamp.
   * @param \Drupal\Component\Datetime\DateTimePlus $end_date
   *   End date timestamp.
   *
   * @return string
   *   A formatted date string.
   */
  private function groupDates(DateTimePlus $start_date, DateTimePlus $end_date) {
    $format_type = $this->getSetting('format_type');
    /** @var \Drupal\Core\Datetime\DateFormatInterface $date_format */
    $date_format = \Drupal::entityTypeManager()
      ->getStorage('date_format')
      ->load($format_type);

    $start = date_parse($start_date);
    $end = date_parse($end_date);

    if ($start['year'] != $end['year']) {
      return $this->formatDateYear($start_date, $end_date);
    }

    if ($start['month'] == $end['month']) {
      return $this->formatDateMonth($start_date, $end_date, $date_format);
    }
    else {
      return $this->formatDateDay($start_date, $end_date, $date_format);
    }
  }

  /**
   * Creates a formatted date value with grouped year.
   *
   * @param \Drupal\Component\Datetime\DateTimePlus $start_date
   *   Start date object.
   * @param \Drupal\Component\Datetime\DateTimePlus $end_date
   *   End date object.
   *
   * @return string
   *   A formatted date string grouped by year.
   */
  private function formatDateYear(DateTimePlus $start_date, DateTimePlus $end_date) {
    $start = $this->formatDate($start_date);
    $end = $this->formatDate($end_date);

    return $start . $this->getSeparator() . $end;
  }

  /**
   * Creates a formatted date value with grouped month.
   *
   * @param \Drupal\Component\Datetime\DateTimePlus $start_date
   *   Start date object.
   * @param \Drupal\Component\Datetime\DateTimePlus $end_date
   *   End date object.
   * @param \Drupal\Core\Datetime\DateFormatInterface $date_format
   *   Date formatter.
   *
   * @return string
   *   A formatted date string grouped by month.
   */
  private function formatDateMonth(DateTimePlus $start_date, DateTimePlus $end_date, DateFormatInterface $date_format) {
    $split_format = $this->getSplitDatePattern($date_format);
    $start = date_parse($start_date);
    $end = date_parse($end_date);
    $collect_date = '';
    $collect_end = '';
    $time = '';
    $suffix_positions = [];

    foreach ($split_format as $keyformat => $format) {
      if (in_array($format, $this->allDateFormats['day']) && $start['day'] != $end['day']) {
        $collect_date .= $format;
        $day_suffix = '';
        // Ordinal suffixes.
        if (in_array($format, $this->allDateFormats['numericDay'])
        && isset($split_format[$keyformat + 1])
        && in_array($split_format[$keyformat + 1], $this->allDateFormats['ordinalSuffix'])) {
          $day_suffix = $split_format[$keyformat + 1];
          // Suffix positions to skip.
          $suffix_positions[] = $keyformat + 1;
        }
        $collect_date .= $day_suffix . $this->getSeparator() . '%';
        $collect_end = $format . $day_suffix;
      }
      elseif (in_array($format, $this->allDateFormats['time'])
        || $format == $this->getTimeSeparator()
      ) {
        $time .= $format;
      }
      elseif (!in_array($keyformat, $suffix_positions)) {
        $collect_date .= $format;
      }
    }

    if (!empty($time)) {
      // Same day just append time.
      if ($start['day'] == $end['day']) {
        $collect_date .= $time . '%';
        $collect_end .= $time;
      }
    }

    // Format different pieces of date.
    $start_f = $this->groupDateFormat($start_date->getTimestamp(), $collect_date);
    $end_f = $this->groupDateFormat($end_date->getTimestamp(), $collect_end);

    // Replace end date placeholder.
    if (!empty($time)) {
      if ($start['day'] == $end['day']) {
        $new_date = str_replace('%', $this->getSeparator(), $start_f) . $end_f;
      }
      else {
        $time_start = $this->groupDateFormat($start_date->getTimestamp(), $time);
        $time_end = $this->groupDateFormat($end_date->getTimestamp(), $time);
        $start_d_split = explode('%', $start_f);
        $new_date = isset($start_d_split[0]) ? $start_d_split[0] : '';
        $new_date .= isset($start_d_split[1]) ? $start_d_split[1] : '';
        $new_date .= $time_start;
        $new_date .= $this->getSeparator();
        $new_date .= $end_f;
        $new_date .= isset($start_d_split[1]) ? $start_d_split[1] : '';
        $new_date .= $time_end;
      }
    }
    else {
      $new_date = str_replace('%', $end_f, $start_f);
    }

    return $new_date;
  }

  /**
   * Creates a formatted date value with grouped day.
   *
   * @param \Drupal\Component\Datetime\DateTimePlus $start_date
   *   Start date object.
   * @param \Drupal\Component\Datetime\DateTimePlus $end_date
   *   End date object.
   * @param \Drupal\Core\Datetime\DateFormatInterface $date_format
   *   Date formatter.
   *
   * @return string
   *   A formatted date string grouped by day.
   */
  private function formatDateDay(DateTimePlus $start_date, DateTimePlus $end_date, DateFormatInterface $date_format) {
    $separator = $this->getSeparator();
    $split_format = $this->getSplitDatePattern($date_format);
    $collect_date = '';
    $collect_year = '';

    foreach ($split_format as $format) {
      if (in_array($format, $this->allDateFormats['month'])) {
        $is_month = TRUE;
        $is_date = TRUE;
        $collect_date .= $format;
      }
      elseif (in_array($format, $this->allDateFormats['day'])) {
        $is_day = TRUE;
        $is_date = TRUE;
        $collect_date .= $format;
      }
      elseif ((empty($is_month) || empty($is_day)) && !in_array($format, $this->allDateFormats['year'])) {
        if (!empty($collect_year) && !isset($is_date)) {
          $year_first = TRUE;
          $collect_year .= $format;
        }
        else {
          $collect_date .= $format;
        }
      }
      else {
        $collect_year .= $format;
      }
    }

    // Format different pieces of date.
    $start = $this->groupDateFormat($start_date->getTimestamp(), $collect_date);
    $end = $this->groupDateFormat($end_date->getTimestamp(), $collect_date);
    $year = $this->groupDateFormat($start_date->getTimestamp(), $collect_year);

    // Concatenate final date format.
    if (isset($year_first)) {
      $group_date_format = $year . $start . $separator . $end;
    }
    else {
      $group_date_format = $start . $separator . $end . $year;
    }

    return $group_date_format;
  }

  /**
   * Get date separator from formatter configuration.
   *
   * @return string|null
   *   Dates separator.
   */
  private function getSeparator() {
    return $this->getSetting('separator');
  }

  /**
   * Get time separator from formatter configuration.
   *
   * @return string|null
   *   Time separator.
   */
  private function getTimeSeparator() {
    return $this->getSetting('time_separator');
  }

  /**
   * Convert date pattern to an array of date elements.
   *
   * @param \Drupal\Core\Datetime\DateFormatInterface $date_format
   *   Date format object.
   *
   * @return array
   *   Date format pattern.
   */
  private function getSplitDatePattern(DateFormatInterface $date_format) {
    return str_split($date_format->getPattern(), 1);
  }

  /**
   * Formats a date, using a custom date format string.
   *
   * @param int $timestamp
   *   A UNIX timestamp to format.
   * @param string $format
   *   A PHP date format string.
   *
   * @return string
   *   Date string in the requested format.
   */
  private function groupDateFormat($timestamp, $format) {
    return $this->dateFormatter->format($timestamp, 'custom', $format);
  }

}
