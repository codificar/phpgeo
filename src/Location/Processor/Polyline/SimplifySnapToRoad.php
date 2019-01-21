<?php

namespace Location\Processor\Polyline;

use Location\Polyline;
use Location\Coordinate;
use DateTime;

/**
 * Modifica Polyline utilizando a API Google Snap to Road.
 *
 */
class SimplifySnapToRoad implements SimplifyInterface
{

    private $estimatedPoints;
    private static $key;

    public function __construct($estimatedPoints, $key)
    {
        $this->estimatedPoints = $estimatedPoints;
        self::$key = $key;
    }

    public function setEstimatedPoints($estimatedPoints)
    {
        $this->estimatedPoints = $estimatedPoints;
    }

    /**
     * Encaixa os pontos na via (Snap to Road API), ignorando os pontos já estimados pela Directions API
     * 
     * @param Polyline $polyline
     *
     * @return Polyline
     */
    public function simplify(Polyline $polyline)
    {
        if($this->estimatedPoints != null)
        {
            $filteredPoly = $this->snapToRoad($polyline, $this->estimatedPoints);
            $this->estimatedPoints = null;
            return $filteredPoly;
        }
        //nao setou pontos estimados
        else
        {
            //\Log::error
            return $polyline;
        }
    }

    private function snapToRoad($polyline, $estimatedPoints)
    {
        $total = $polyline->getNumberOfPoints();

        //Limite de 100 pontos por requisição do google
        $snapPoints = new Polyline();
        $estimatedSnapped = [];

        $path = "";
        $count = 0;
        for($i = 0; $i < count($estimatedPoints); $i++)
        {
            // chegou a ponto estimado
            if($estimatedPoints[$i])
            {
                // existe buffer de pontos para processar
                if($count > 0)
                {
                    // processa snap no segmento acumulado previamente
                    $path = substr($path, 0, -1);
                    $segment = self::getSnappedSegment($path);
                    $snapPoints->addPoints($segment);
                    $estimatedSnapped = array_merge($estimatedSnapped, array_pad([], count($segment), false));
                    $count = 0;
                    $path = "";
                }
                // adiciona ponto estimado
                $estimatedSnapped[] = true;
                $snapPoints->addPoint($polyline->getPoints()[$i]);
            }
            // ponto nao estimado (coordenada real do gps)
            else
            {
                // acumula ponto nao estimado no buffer
                if($count <= 100)
                {
                    $path = $path . $polyline->getPoints()[$i]->getLat() . "," . $polyline->getPoints()[$i]->getLng();
                    $path = $path . "|";
                    $count++;
                }
                // buffer cheio, processar pontos acumulados e zerar buffer
                if($count == 100)
                {
                    $path = substr($path, 0, -1);
                    $segment = self::getSnappedSegment($path);
                    $snapPoints->addPoints($segment);
                    $estimatedSnapped = array_merge($estimatedSnapped, array_pad([], count($segment), false));
                    $count = 0;
                    $path = "";
                }
            }
        }
        if($count > 0)
        {
            $path = substr($path, 0, -1);
            $segment = self::getSnappedSegment($path);
            $snapPoints->addPoints($segment);
            $estimatedSnapped = array_merge($estimatedSnapped, array_pad([], count($segment), false));
        }

        return [$snapPoints, $estimatedSnapped];
    }

    private static function getSnappedSegment($path)
    {
        $curl_string = "https://roads.googleapis.com/v1/snapToRoads?path=" . $path . "&key=" . self::$key . "&interpolate=false";
        $session = curl_init($curl_string);
        curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($session, CURLOPT_SSL_VERIFYPEER, false);
        $msg_chk = curl_exec($session);
        $msg_chk = json_decode($msg_chk);

        $segment = [];
        try {
            foreach ($msg_chk->snappedPoints as $spoint) {
                $segment[] = new Coordinate($spoint->location->latitude, $spoint->location->longitude);
            }
        } catch(Exception $ex) {
            return [];
        }

        return $segment;
    }
}