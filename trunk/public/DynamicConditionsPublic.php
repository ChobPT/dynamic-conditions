<?php namespace Pub;

/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://www.rto.de
 * @since      1.0.0
 *
 * @package    DynamicConditions
 * @subpackage DynamicConditions/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    DynamicConditions
 * @subpackage DynamicConditions/public
 * @author     RTO GmbH <kundenhomepage@rto.de>
 */
class DynamicConditionsPublic {

    /**
     * The ID of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string $pluginName The ID of this plugin.
     */
    private $pluginName;

    /**
     * The version of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string $version The current version of this plugin.
     */
    private $version;

    private $elementSettings = [];

    private $dateInstance;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param      string $pluginName The name of the plugin.
     * @param      string $version The version of this plugin.
     */
    public function __construct( $pluginName, $version ) {

        $this->pluginName = $pluginName;
        $this->version = $version;
        $this->dateInstance = new DynamicConditionsDate();

    }

    /**
     * Gets settings with english locale (needed for date)
     *
     * @param $element
     * @return mixed
     */
    private function getElementSettings( $element ) {
        $id = $element->get_id();
        if ( !empty( $this->elementSettings[$id] ) ) {
            return $this->elementSettings[$id];
        }

        global $wp_locale;

        $currentLocale = get_locale();
        setlocale( LC_ALL, 'en_GB' );
        $wp_locale = 'en_GB';

        add_filter( 'date_i18n', [ $this->dateInstance, 'filterDateI18n' ], 10, 4 );
        add_filter( 'get_the_date', [ $this->dateInstance, 'filterPostDate' ], 10, 3 );
        add_filter( 'get_the_modified_date', [ $this->dateInstance, 'filterPostDate' ], 10, 3 );
        $this->elementSettings[$id] = $element->get_settings_for_display();
        remove_filter( 'date_i18n', [ $this->dateInstance, 'filterDateI18n' ] );
        remove_filter( 'get_the_date', [ $this->dateInstance, 'filterPostDate' ] );
        remove_filter( 'get_the_modified_date', [ $this->dateInstance, 'filterPostDate' ] );


        setlocale( LC_ALL, $currentLocale );
        $wp_locale = $currentLocale;

        return $this->elementSettings[$id];
    }


    /**
     * Stopp rendering of widget if its hidden
     *
     * @param $content
     * @param $widget
     * @return string
     */
    public function filterWidgetContent( $content, $widget = null ) {
        if ( \Elementor\Plugin::$instance->editor->is_edit_mode() || empty( $widget ) ) {
            return $content;
        }

        $settings = $this->getElementSettings( $widget );

        $hide = $this->checkCondition( $settings );


        if ( $hide ) {
            return '<!-- hidden widget -->';
        }

        return $content;
    }

    /**
     * Check if section is hidden, before rendering
     *
     * @param $section
     */
    public function filterSectionContentBefore( $section ) {
        $settings = $this->getElementSettings( $section );
        $hide = $this->checkCondition( $settings );

        if ( !$hide ) {
            return;
        }

        $section->dynamicConditionIsHidden = true;

        ob_start();
    }

    /**
     * Clean output of section if it is hidden
     *
     * @param $section
     */
    public function filterSectionContentAfter( $section ) {
        if ( !empty( $section->dynamicConditionIsHidden ) ) {
            ob_end_clean();
            $type = $section->get_type();
            if ( !empty( $section->get_settings( 'dynamicconditions_hideContentOnly' ) ) ) {
                $section->before_render();
                $section->after_render();
            }
            echo '<!-- hidden ' . $type . ' -->';
        }
    }

    /**
     * Checks condition, return if element is hidden
     *
     * @param $settings
     * @return bool
     */
    public function checkCondition( $settings ) {
        if ( empty( $settings['dynamicconditions_condition'] )
        ) {
            // no condition selected - disable conditions
            return false;
        }

        // loop values
        $condition = $this->loopValues( $settings );

        $hide = false;

        $visibility = self::checkEmpty( $settings, 'dynamicconditions_visibility', 'hide' );
        switch ( $visibility ) {
            case 'show':
                if ( !$condition ) {
                    $hide = true;
                }
                break;
            case 'hide':
            default:
                if ( $condition ) {
                    $hide = true;
                }
                break;
        }

        return $hide;
    }

    /**
     * Loop widget-values and check the condition
     *
     * @param $settings
     * @return bool|mixed
     */
    private function loopValues( $settings ) {
        $condition = false;
        $widgetValueArray = self::checkEmpty( $settings, 'dynamicconditions_dynamic' );

        if ( !is_array( $widgetValueArray ) ) {
            $widgetValueArray = [ $widgetValueArray ];
        }

        // get value form conditions
        $compareType = self::checkEmpty( $settings, 'dynamicconditions_type', 'default' );
        list( $checkValue, $checkValue2 ) = $this->getCheckValue( $compareType, $settings );

        foreach ( $widgetValueArray as $widgetValue ) {
            if ( is_array( $widgetValue ) ) {
                if ( !empty( $widgetValue['id'] ) ) {
                    $widgetValue = get_attachment_link( $widgetValue['id'] );
                } else {
                    continue;
                }
            }

            // parse value based on compare-type
            $this->parseWidgetValue( $widgetValue, $compareType );

            // compare widget-value with check-values
            list( $condition, $break, $breakFalse, $continue )
                = $this->compareValues( $settings['dynamicconditions_condition'], $widgetValue, $checkValue, $checkValue2 );

            if ( $break && $condition ) {
                // break if condition is true
                break;
            }

            if ( $breakFalse && !$condition ) {
                // break if condition is false
                break;
            }

            if ( $continue !== false ) {
                continue;
            }
        }

        return $condition;
    }

    /**
     * Compare values
     *
     * @param $widgetValue
     * @param $checkValue
     * @param $checkValue2
     * @return array
     */
    private function compareValues( $compare, $widgetValue, $checkValue, $checkValue2 ) {
        $continue = false;
        $break = false;
        $breakFalse = false;
        $condition = false;

        switch ( $compare ) {
            case 'equal':
                $condition = $checkValue == $widgetValue;
                $break = true;
                break;

            case 'not_equal':
                $condition = $checkValue != $widgetValue;
                $breakFalse = true;
                break;

            case 'contains':
                if ( empty( $checkValue ) ) {
                    $continue = 2;
                }
                $condition = strpos( $widgetValue, $checkValue ) !== false;
                $break = true;
                break;

            case 'not_contains':
                if ( empty( $checkValue ) ) {
                    $continue = 2;
                }
                $condition = strpos( $widgetValue, $checkValue ) === false;
                $breakFalse = true;
                break;

            case 'empty':
                $condition = empty( $widgetValue );
                $breakFalse = true;
                break;

            case 'not_empty':
                $condition = !empty( $widgetValue );
                $break = true;
                break;

            case 'less':
                if ( is_numeric( $widgetValue ) ) {
                    $condition = $widgetValue < $checkValue;
                } else {
                    $condition = strlen( $widgetValue ) < strlen( $checkValue );
                }
                $break = true;
                break;

            case 'greater':
                if ( is_numeric( $widgetValue ) ) {
                    $condition = $widgetValue > $checkValue;
                } else {
                    $condition = strlen( $widgetValue ) > strlen( $checkValue );
                }
                $break = true;
                break;

            case 'between':
                $condition = $widgetValue >= $checkValue && $widgetValue <= $checkValue2;
                $break = true;
                break;
        }

        return [
            $condition,
            $break,
            $breakFalse,
            $continue,
        ];
    }

    /**
     * Parse value of widget to timestamp, day or month
     *
     * @param $widgetValue
     * @param $compareType
     */
    private function parseWidgetValue( &$widgetValue, $compareType ) {
        switch ( $compareType ) {
            case 'days':
                $widgetValue = date( 'N', DynamicConditionsDate::stringToTime( $widgetValue ) );
                break;

            case 'months':
                $widgetValue = date( 'n', DynamicConditionsDate::stringToTime( $widgetValue ) );
                break;

            case 'strtotime':
                // nobreak
            case 'date':
                $widgetValue = DynamicConditionsDate::stringToTime( $widgetValue );
                break;
        }
    }

    /**
     * Get value to compare
     *
     * @param $compareType
     * @param $settings
     * @return array
     */
    private function getCheckValue( $compareType, $settings ) {
        switch ( $compareType ) {
            case 'days':
                $checkValue = self::checkEmpty( $settings, 'dynamicconditions_day_value' );
                $checkValue2 = self::checkEmpty( $settings, 'dynamicconditions_day_value2' );
                $checkValue = DynamicConditionsDate::unTranslateDate( $checkValue );
                $checkValue2 = DynamicConditionsDate::unTranslateDate( $checkValue2 );
                break;

            case 'months':
                $checkValue = self::checkEmpty( $settings, 'dynamicconditions_month_value' );
                $checkValue2 = self::checkEmpty( $settings, 'dynamicconditions_month_value2' );
                $checkValue = DynamicConditionsDate::unTranslateDate( $checkValue );
                $checkValue2 = DynamicConditionsDate::unTranslateDate( $checkValue2 );
                break;

            case 'date':
                $checkValue = self::checkEmpty( $settings, 'dynamicconditions_date_value' );
                $checkValue2 = self::checkEmpty( $settings, 'dynamicconditions_date_value2' );
                $checkValue = DynamicConditionsDate::stringToTime( $checkValue );
                $checkValue2 = DynamicConditionsDate::stringToTime( $checkValue2 );
                break;

            case 'strtotime':
                $checkValue = self::checkEmpty( $settings, 'dynamicconditions_value' );
                $checkValue2 = self::checkEmpty( $settings, 'dynamicconditions_value2' );
                $checkValue = DynamicConditionsDate::unTranslateDate( $checkValue );
                $checkValue2 = DynamicConditionsDate::unTranslateDate( $checkValue2 );
                $checkValue = DynamicConditionsDate::stringToTime( $checkValue );
                $checkValue2 = DynamicConditionsDate::stringToTime( $checkValue2 );
                break;

            case 'default':
            default:
                $checkValue = self::checkEmpty( $settings, 'dynamicconditions_value' );
                $checkValue2 = self::checkEmpty( $settings, 'dynamicconditions_value2' );
                break;
        }

        return [
            $checkValue,
            $checkValue2,
        ];
    }

    /**
     * Checks if an array or entry in array is empty and return its value
     *
     * @param array $array
     * @param null $key
     * @return array|mixed|null
     */
    public static function checkEmpty( $array = [], $key = null, $fallback = null ) {
        if ( empty( $key ) ) {
            return !empty( $array ) ? $array : $fallback;
        }

        return !empty( $array[$key] ) ? $array[$key] : $fallback;
    }

}
