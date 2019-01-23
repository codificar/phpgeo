<?php

namespace Location\Processor\Polyline;

use Location\Polyline;
use Location\Coordinate;
use DateTime;

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

    private static $key; // Google maps API key

    public function __construct($maxVelocity, $maxDistance, $timestamps, $key)
    {
        $this->maxVelocity = $maxVelocity;
        $this->maxDistance = $maxDistance;
        $this->timestamps = $timestamps;
        $this->estimatedPoints = [];
        self::$key = $key;
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
            if($last_position)
            {
                // Calcula distancia e velocidade media
                $distance = distanceGeoPoints($last_position->getLat(), $last_position->getLng(), $point->getLat(), $point->getLng());
                $velocity = $this->getVelocity($distance, $this->timestamps[$last_i], $this->timestamps[$i]);
                // dump($i.", ".$distance.", ".$velocity);
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

        try {
            $resp_directions = self::getDirections($initial_point->getLat(), $initial_point->getLng(), $end_point->getLat(), $end_point->getLng());
        } catch (Exception $e) {
            $resp_directions['success'] = false;
        }

        if ($resp_directions['success'] == true) {
            foreach($resp_directions['data'][0]['overview_polyline']['points'] as $point)
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

    public static function getDirections($startLat, $startLng, $destLat, $destLng) {
        // $curl_string = "https://maps.googleapis.com/maps/api/directions/json?origin=" . $startLat . "," . $startLng . "&destination=" . $destLat . "," . $destLng . "&key=AIzaSyCftPfltoHQKwVdH_jRneLccwEvyqxgexs" . "&decodePolyline=1";

        // $session = curl_init($curl_string);
        // curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
        // curl_setopt($session, CURLOPT_SSL_VERIFYPEER, false);
        // $msg_chk = curl_exec($session);
        // $response = json_decode($msg_chk);


        $response = \GoogleMaps::load('directions')
        ->setparam(
            [
                'key' => self::$key,
                'origin' => ($startLat . "," . $startLng),
                'destination' => ($destLat . "," . $destLng)
            ]
        )->get();

        $response = json_decode($response, true);

        $response_array = array();

        if ($response['status'] != "OK"){
            $response_array['success'] = false;
            $response_array['message'] = "No results";
            $response_array['data'] = [];

            return $response_array;
        }

        $response_array['success'] = true;
        $response_array['data'] = $response['routes'];

        return $response_array;
    }

}