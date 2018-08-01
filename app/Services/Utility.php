<?php namespace App\Services;

class Utility  {
    public function getRecentArrayValues($array, $itemCount) {
        return array_slice($array, sizeof($array) - $itemCount);
    }

    public function getMultipleArraySets($array, $itemCount, $endOffset) {
        //itemCount is the amount of items in each array
        //endOffset is the amount of arrays

        $endOffset = $endOffset-1;
        $responseArray = [];
        while($endOffset >= 0) {
            $currentArray = $array;

            $currentArray = array_slice($currentArray, 0, sizeof($currentArray)-$endOffset);

            $responseArray[] = array_slice($currentArray, sizeof($currentArray) - $itemCount);
            $endOffset--;
        }
        return $responseArray;
    }

    public function getSumOfArrayProperty($array, $property) {
        $sum = 0;
        foreach($array as $element){
                if (isset($element[$property]))
                $sum += $element[$property];
        }
        return $sum;
    }

    public function minAttributeInArrayOfArrays($array, $prop) {
        return min(array_map(function($element) use($prop) {
            return $element[$prop];
        },
            $array));
    }

    public function recursiveClearDirectoryFiles($directory) {
        foreach(glob("{$directory}/*") as $file)
        {
            if(is_dir($file)) {
                recursiveRemoveDirectory($file);
            } else {
                unlink($file);
            }
        }
    }

    public function getEndValuesOfArray($array, $valueCount) {
        $arrayStart = sizeof($array) - $valueCount;
        $array = array_slice($array, $arrayStart);
        return $array;
    }

    public function trimArraysInComplexArray($complexArray, $maxValues = 5) {
        foreach ($complexArray as $index=>$item) {
            if (is_array($item)) {
                $complexArray[$index] = $this->getLastXElementsInArray($item, $maxValues);
                $innerArray = $complexArray[$index];
                foreach($innerArray as $innerIndex=>$possibleArray) {
                    if (is_array($possibleArray)) {
                        $complexArray[$index][$innerIndex] = $this->getLastXElementsInArray($possibleArray, $maxValues);
                    }
                }
            }
        }
        return $complexArray;
    }

    public function returnArrayObjectWithProperty($array, $search_property, $value) {
        foreach ($array as $element) {
            if ($element[$search_property] == $value) {
                return $element;
                break;
            }
        }
    }

    public function getLastXElementsInArray($array, $elementCount) {
        $startIndex = sizeof($array) - $elementCount;

        return array_slice($array, $startIndex, $elementCount);
    }

    public function shortenComplexArray($array, $size = 5) {
        $returnArray = [];

        foreach ($array as $index=>$iterationArray) {
            $returnArray[$index] = $this->getLastXElementsInArray($iterationArray, $size);
        }
        return $returnArray;
    }

    public function findArrayIndexWithElementAttribute($array, $elementId, $searchValue) {
        foreach($array as $index => $element) {
            if($element[$elementId] == $searchValue) return $index;
        }
        return FALSE;
    }

    public function arrayAttributeToSimpleArray($array, $attribute) {
        return array_map(function($element) use ($attribute) {
            if (is_numeric($element[$attribute])) {
                return round($element[$attribute]);
            }
            else {
                return $element[$attribute];
            }
        },$array);
    }

    public function waitUntilSeconds($seconds) {
        $currentSeconds = date('s', time());
        $secondDiff = $seconds - $currentSeconds;

        if ($secondDiff <= 0) {
            return true;
        }
        else {
            sleep($secondDiff);
        }
    }

    public function getXFromLastValue($array, $fromLastCount) {
        return $array[count($array)-($fromLastCount + 1)];
    }

    public function hasNegativeCheck($array) {
        return min($array) < 0;
    }

    public function hasPositiveCheck($array) {
        return max($array) > 0;
    }

    public function mysqlDateTime() {
        return date('Y-m-d H:i:s');
    }

    public function checkCrossOverLevel($array, $crossLevel) {
        $secondToLastValue = $this->getXFromLastValue($array, 1);
        $lastValue = end($array);

        if ($secondToLastValue <= $crossLevel && $lastValue > $crossLevel) {
            return 'crossedAbove';
        }
        elseif ($secondToLastValue >= $crossLevel && $lastValue < $crossLevel) {
            return 'crossedBelow';
        }
        else {
            return false;
        }
    }

    public function removeLastValueInArray($array) {
        unset($array[count($array)-1]);
        return $array;
    }
}