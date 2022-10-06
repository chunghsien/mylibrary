<?php

namespace Chopin\Support;

abstract class DateTools
{
    /**
     * @desc 代入年跟月求當月有幾天
     * @param int $year
     * @param int $month
     * @return int
     */
    static public function daysInTheMonth($year, $month) {
        $month = preg_replace('/^0{1}/', '', $month);
        $thirtyOneDaysInMonth = [1,3,5,7,8,10,12];
        $thirtyDaysInMonth = [4,6,9,11];
        $year = intval($year);
        $month = intval($month);
        if (false === array_search($month, $thirtyOneDaysInMonth) && false === array_search($month, $thirtyDaysInMonth)) {
            if ($year % 4 == 0) {
                return 29;
            } else {
                return 28;
            }
        }
        if (false !== array_search($month, $thirtyOneDaysInMonth)) {
            return 31;
        }
        if (false !== array_search($month, $thirtyDaysInMonth)) {
            return 30;
        }
        return 0;
    }

}
