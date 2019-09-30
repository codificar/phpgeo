<?php

namespace Location\Processor\Polyline;

use Location\Polyline;
use Location\Coordinate;
use DateTime;
use MapsFactory;

/**
 * Simplifica Polyline descartando anomalias (outliers), e completa as falhas na rota utilizando a API Google Directions.
 *
 */
class SimplifyOutlierFillGaps implements SimplifyInterface
{

    private $maxVelocity; // m/s
    private $maxDistance; // m
    private $timestamps;
    private $estimatedPoints;

    public function __construct($maxVelocity, $maxDistance, $timestamps)
    {
        $this->maxVelocity = $maxVelocity;
        $this->maxDistance = $maxDistance;
        $this->timestamps = $timestamps;
        $this->estimatedPoints = [];
    }

    public function setTimestamps($timestamps)
    {
        $this->timestamps = $timestamps;
    }

    /**
     * @param Polyline $polyline
     *
     * @return Polyline
     */
    public function simplify(Polyline $polyline)
    {
        if(!$this->timestamps || count($this->timestamps) != $polyline->getNumberOfPoints())
        {
            throw new Exception("Update the timestamps with the trip associated values", 1);  
        }
        $last_position = null;
        $last_i = 0;
        $filteredPoly = new Polyline();
        $this->estimatedPoints = [];
        foreach($polyline->getPoints() as $i=>$point)
        {
            // dump($i);
            if($last_position){
                // Calcula distancia e velocidade media
                $distance = $this->distanceGeoPoints($last_position->getLat(), $last_position->getLng(), $point->getLat(), $point->getLng());
                $velocity = $this->maxVelocity;

                if(array_key_exists($last_i, $this->timestamps) && array_key_exists($i, $this->timestamps))
                    $velocity = $this->getVelocity($distance, $this->timestamps[$last_i], $this->timestamps[$i]);

                // Velocidade media dentro do limite, nao eh outlier
                if($distance > 0 && $velocity <= $this->maxVelocity)
                {
                    // Distancia maior que limite (perda de dados), completa rota com estimativa da API Google Directions
                    if($distance >= $this->maxDistance)
                    {
                        // Remove ultimo elemento (evitar duplicados)
                        $filteredPoly->popPoint();
                        // adiciona estimativa de rota (Google API), incluindo ultimo elemento
                        $estimatedRoute = self::generateRoute($last_position, $point);
                        $filteredPoly->addPoints($estimatedRoute);
                        // adiciona condicional para ponto estimado (excluindo inicio e fim)
                        for($i = 0; $i<(count($estimatedRoute) - 2); $i++)
                        {
                            $this->estimatedPoints[] = true;
                        }
                        // adiciona condicional para ponto final do segmento (nao estimado)
                        $this->estimatedPoints[] = false;
                    }
                    // Adiciona ponto
                    else
                    {
                        $filteredPoly->addPoint($point);
                        $this->estimatedPoints[] = false;
                    }
                    $last_position = $point;
                    $last_i = $i;
                }
            }
            // 1o elemento da rota
            else
            {
                $filteredPoly->addPoint($point);
                $last_position = $point;
                $this->estimatedPoints[] = false;
            }

        }
        return $filteredPoly;
    }


    public function getIsEstimated()
    {
        return $this->estimatedPoints;
    }

    public function getVelocity($distance, $timestamp1, $timestamp2)
    {
        $time = ($timestamp2 - $timestamp1)/1000;

        if($time > 0)
            // m/s
            return $distance / $time;
        else
            return $this->maxVelocity;
    }

    /**
     * Gera uma rota com a API google directions
     * 
     * @param Coordinate $inicial_point ponto inicial
     * @param Coordinate $end_point ponto final
     * 
     * @return Coordinate[] pontos estimados
     */
    public static function generateRoute($initial_point, $end_point) {
        $resp_directions = array();
        $points = [];

        $resp_directions = self::getDirections($initial_point->getLat(), $initial_point->getLng(), $end_point->getLat(), $end_point->getLng());

        if (is_array($resp_directions) && count($resp_directions)) {
            foreach($resp_directions as $point)
            {
                $points[] = new Coordinate($point['lat'], $point['lng']);
            }
        }
        else {
            $points[]= $initial_point;
            $points[]= $end_point;
        }

        return $points;
    }

    public static function getDirections($startLat, $startLng, $destLat, $destLng)
    {
        $factory = new MapsFactory('directions');
        $clicker = $factory->createMaps();
        $response_array = array();

        try {
            $polyline = $clicker->getPolylineAndEstimateByDirections($startLat, $startLng, $destLat, $destLng);
        } catch (Exception $e) {
            return $response_array;
        }

        if(!$polyline)
            return $response_array;

        $response = json_encode($polyline, JSON_PRETTY_PRINT);
        $response = json_decode($response, true);

        $response_array = $response['points'];

        return $response_array;
    }

    public function distanceGeoPoints ($lat1, $lng1, $lat2, $lng2) {

        $earthRadius = 3958.75;
    
        $dLat = deg2rad($lat2-$lat1);
        $dLng = deg2rad($lng2-$lng1);
    
    
        $a = sin($dLat/2) * sin($dLat/2) +
           cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
           sin($dLng/2) * sin($dLng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        $dist = $earthRadius * $c;
    
        // from miles
        $meterConversion = 1609.34;
        $geopointDistance = $dist * $meterConversion;
    
        return $geopointDistance;
    }
}
