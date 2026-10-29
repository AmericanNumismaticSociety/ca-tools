<?xml version="1.0" encoding="UTF-8"?>
<!-- Date: October 2026
    Function: Use to add METS image elements to a folder of NUDS XML files to subvert CA workflow    
    -->
<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns="http://nomisma.org/nuds" xmlns:nuds="http://nomisma.org/nuds" xmlns:mets="http://www.loc.gov/METS/"
    xmlns:xs="http://www.w3.org/2001/XMLSchema"
    exclude-result-prefixes="xs"
    version="2.0">
    
    <xsl:strip-space elements="*"/>
    
    <xsl:output method="xml" encoding="UTF-8" indent="yes"/>
    
    <xsl:template match="@*|node()">
        <xsl:copy>
            <xsl:apply-templates select="@*|node()"/>
        </xsl:copy>
    </xsl:template>
    
    <xsl:template match="nuds:nuds">
        <xsl:variable name="recordId" select="nuds:control/nuds:recordId"/>
        
        <xsl:variable name="accyear" select="tokenize($recordId, '\.')[1]"/>
        
        <xsl:variable name="accgroup">
            <xsl:choose>
                <xsl:when test="number($accyear) &lt; 1900">00001899</xsl:when>
                <xsl:when test="number($accyear) &gt;= 1900 and number($accyear) &lt; 1950">19001949</xsl:when>
                <xsl:when test="number($accyear) &gt;= 1950 and number($accyear) &lt; 2000">19501999</xsl:when>
                <xsl:when test="number($accyear) &gt;= 2000 and number($accyear) &lt; 2050">20002049</xsl:when>
            </xsl:choose>
        </xsl:variable>
        
        
        <nuds xmlns="http://nomisma.org/nuds" xmlns:mets="http://www.loc.gov/METS/" xmlns:tei="http://www.tei-c.org/ns/1.0" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xlink="http://www.w3.org/1999/xlink" recordType="physical">
            <xsl:apply-templates/>
            
            <xsl:if test="not(nuds:digRep)">
                <digRep>
                    <mets:fileSec>
                        <mets:fileGrp USE="obverse">
                            <mets:file USE="iiif">
                                <mets:FLocat LOCYPE="URL" xlink:href="https://images.numismatics.org/collectionimages%2F{$accgroup}%2F{$accyear}%2F{$recordId}.obv.noscale.jpg"/>
                            </mets:file>
                            <mets:file USE="archive" MIMETYPE="image/jpeg">
                                <mets:FLocat LOCYPE="URL" xlink:href="https://numismatics.org/collectionimages/{$accgroup}/{$accyear}/{$recordId}.obv.noscale.jpg"/>
                            </mets:file>
                            <mets:file USE="reference" MIMETYPE="image/jpeg">
                                <mets:FLocat LOCYPE="URL" xlink:href="https://numismatics.org/collectionimages/{$accgroup}/{$accyear}/{$recordId}.obv.width350.jpg"/>
                            </mets:file>
                            <mets:file USE="thumbnail" MIMETYPE="image/jpeg">
                                <mets:FLocat LOCYPE="URL" xlink:href="https://numismatics.org/collectionimages/{$accgroup}/{$accyear}/{$recordId}.obv.width175.jpg"/>
                            </mets:file>
                        </mets:fileGrp>
                        <mets:fileGrp USE="reverse">
                            <mets:file USE="iiif">
                                <mets:FLocat LOCYPE="URL" xlink:href="https://images.numismatics.org/collectionimages%2F{$accgroup}%2F{$accyear}%2F{$recordId}.rev.noscale.jpg"/>
                            </mets:file>
                            <mets:file USE="archive" MIMETYPE="image/jpeg">
                                <mets:FLocat LOCYPE="URL" xlink:href="https://numismatics.org/collectionimages/{$accgroup}/{$accyear}/{$recordId}.rev.noscale.jpg"/>
                            </mets:file>
                            <mets:file USE="reference" MIMETYPE="image/jpeg">
                                <mets:FLocat LOCYPE="URL" xlink:href="https://numismatics.org/collectionimages/{$accgroup}/{$accyear}/{$recordId}.rev.width350.jpg"/>
                            </mets:file>
                            <mets:file USE="thumbnail" MIMETYPE="image/jpeg">
                                <mets:FLocat LOCYPE="URL" xlink:href="https://numismatics.org/collectionimages/{$accgroup}/{$accyear}/{$recordId}.rev.width175.jpg"/>
                            </mets:file>
                        </mets:fileGrp>
                    </mets:fileSec>
                </digRep>
            </xsl:if>
            
        </nuds>
    </xsl:template>
    
</xsl:stylesheet>